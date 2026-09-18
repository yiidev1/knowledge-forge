<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\AiAudio\File;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Tts\GeneratedAudioStorage;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Web\Job\JobPageGuard;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function filesize;
use function in_array;
use function is_string;
use function max;
use function min;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

use const PATHINFO_FILENAME;

/**
 * Serves one generated audio file (GET /audio-to-text/job/{publicId}/ai-audio/file).
 *
 * The only route in this application that streams binary content, so a few things it does are new here
 * and each one is load-bearing.
 *
 * ## The emitter rewinds the body, so a plain `fseek` would serve the wrong bytes
 *
 * `Yiisoft\PsrEmitter\SapiEmitter::emitBody()` calls `$body->rewind()` before it emits anything. A range
 * implemented the obvious way — open the file, seek to the start offset, set `Content-Range` — is
 * therefore rewound to byte zero and streamed to the end: wrong content, and a length that contradicts
 * the header. {@see LimitStream} is the fix, because its own `rewind()` returns to the range offset and
 * its `getSize()` and `eof()` respect the end.
 *
 * Range support is not optional here. Safari opens an `<audio>` element with `Range: bytes=0-1` and will
 * not play a source that answers 200, and without it nobody can scrub a five-minute recording.
 *
 * ## Caching, unlike every other route in this feature
 *
 * Everything else here sends `no-store`. For an immutable multi-megabyte file that would mean a complete
 * re-download on every seek. The file's name contains the transcript digest and its content never
 * changes, so it gets an `ETag` and a revalidation policy instead — still `private`, still never stored
 * by a shared cache.
 *
 * ## Authorisation
 *
 * The administrator gate is route-group middleware. Beyond that, every failure — unknown id, wrong type,
 * nothing generated, a filename that is not ours, a file that has gone — collapses to the same 404
 * through {@see JobPageGuard}, so the response cannot be used to learn which of those was true.
 * `GeneratedAudioStorage` re-validates the stored filename before it becomes a path, so a tampered
 * database value cannot address anything outside the generated-audio tree, and cannot name an original
 * recording at all.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private TtsGenerationService $generation,
        private GeneratedAudioStorage $storage,
        private AudioToTextSettings $settings,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId, ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $available = $this->generation->availableTypes($job);
        $requested = $request->getQueryParams()['type'] ?? null;

        // The type is optional because a recording produces exactly one output today. Accepting it
        // anyway means the URL keeps working unchanged when that stops being true.
        $outputType = is_string($requested) ? TtsOutputType::fromSlug($requested) : ($available[0] ?? null);

        if ($outputType === null || !in_array($outputType, $available, true)) {
            return $this->guard->notFound();
        }

        $rendition = $this->generation->find($job, $outputType);
        $path = $rendition === null ? null : $this->storage->pathFor($job->publicId, $rendition->fileName);
        $size = $path === null ? false : filesize($path);

        if ($path === null || $size === false || $size <= 0) {
            return $this->guard->notFound();
        }

        $format = $this->settings->tts->outputFormat;
        $etag = '"' . ($rendition?->fileHash ?? '') . '"';

        if ($this->matchesEtag($request, $etag)) {
            return $this->headers($this->responseFactory->createResponse(304), $etag, $format->contentType())
                ->withHeader('Content-Length', '0');
        }

        $stream = Utils::streamFor(Utils::tryFopen($path, 'rb'));
        $range = $this->range($request, $size);

        if ($range === null) {
            return $this->headers($this->responseFactory->createResponse(200), $etag, $format->contentType())
                ->withHeader('Content-Length', (string) $size)
                ->withHeader('Content-Disposition', $this->disposition($request, $job->originalFilename, $outputType, $format->extension()))
                ->withBody($stream);
        }

        if ($range === false) {
            // Unsatisfiable. RFC 9110 requires the total length so the client can correct itself rather
            // than guess.
            return $this->headers($this->responseFactory->createResponse(416), $etag, $format->contentType())
                ->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return $this->headers($this->responseFactory->createResponse(206), $etag, $format->contentType())
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size))
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Disposition', $this->disposition($request, $job->originalFilename, $outputType, $format->extension()))
            ->withBody(new LimitStream($stream, $length, $start));
    }

    /**
     * Headers every response carries, whatever its status.
     *
     * `Accept-Ranges` is on the 200 as well as the 206 deliberately: a client learns that ranges are
     * available from the full response, and one that never sees it will not ask.
     */
    private function headers(ResponseInterface $response, string $etag, string $contentType): ResponseInterface
    {
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('ETag', $etag)
            // Revalidate every time, but allow the browser to keep its copy — which is what makes
            // seeking in a long recording cost one 304 rather than another download.
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate')
            // The Content-Type is exact, and `nosniff` is set application-wide, so a browser plays this
            // or refuses it — it never guesses.
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function matchesEtag(ServerRequestInterface $request, string $etag): bool
    {
        $header = $request->getHeaderLine('If-None-Match');

        return $header !== '' && ($header === $etag || $header === 'W/' . $etag || $header === '*');
    }

    /**
     * Parse a single byte range.
     *
     * Returns `null` for "no range, send everything", `false` for unsatisfiable, or the resolved
     * inclusive `[start, end]`.
     *
     * Deliberately handles one range only. Multiple ranges require a multipart response that nothing
     * asking for audio sends, and answering the whole file is an explicitly permitted response to a
     * range request — so the complexity buys nothing real.
     *
     * @return array{int, int}|false|null
     */
    private function range(ServerRequestInterface $request, int $size): array|false|null
    {
        $header = $request->getHeaderLine('Range');

        if ($header === '') {
            return null;
        }

        // Only `bytes` is a unit this serves; any other unit must be ignored rather than refused.
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
            return null;
        }

        [$from, $to] = [$m[1], $m[2]];

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // A suffix range: the last N bytes. N larger than the file is not an error — it means the
            // whole file.
            $length = (int) $to;

            return $length <= 0 ? false : [max(0, $size - $length), $size - 1];
        }

        $start = (int) $from;
        $end = $to === '' ? $size - 1 : (int) $to;

        if ($start > $end || $start >= $size) {
            return false;
        }

        // A client may ask past the end; the answer is what exists, not an error.
        return [$start, min($end, $size - 1)];
    }

    /**
     * `inline` so the player can use it, `attachment` only when Download was pressed.
     *
     * The filename is built from the original recording's name and the output type, and folded through
     * a character class that cannot contain a quote, a semicolon or a newline — so no part of it can
     * escape the header it sits in.
     */
    private function disposition(
        ServerRequestInterface $request,
        string $originalFilename,
        TtsOutputType $outputType,
        string $extension,
    ): string {
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalFilename, PATHINFO_FILENAME));
        $stem = trim((string) $stem, '-');

        $name = sprintf('%s-ai-%s.%s', $stem === '' ? 'recording' : $stem, $outputType->slug(), $extension);
        $wantsDownload = ($request->getQueryParams()['download'] ?? null) !== null;

        return sprintf('%s; filename="%s"', $wantsDownload ? 'attachment' : 'inline', $name);
    }
}
