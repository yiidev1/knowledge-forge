<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Original\File;

use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\Job\JobPageGuard;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function filemtime;
use function filesize;
use function max;
use function min;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * Serves the recording a person uploaded (GET /audio-to-text/job/{publicId}/original/file).
 *
 * The AI audio page could describe the original but never play it, which made the one thing the page is
 * about — *this recording became that reading* — impossible to check by ear. This is the endpoint that
 * closes that gap, and it is deliberately the twin of
 * {@see \App\AudioToText\Web\Job\AiAudio\File\Action}: same range handling, same caching posture, same
 * collapse-everything-to-404 rule. Where the two differ, the difference is noted below.
 *
 * ## It composes no path of its own
 *
 * The filename comes from `audio_transcription_jobs.retained_audio_path`, and it is resolved through
 * {@see QueuedAudioStorage::retainedPathFor()} — the same method the retention sweep and the worker use.
 * That method re-validates the stored value against `/^source\.[a-z0-9]{1,10}$/` and rejects a public id
 * that is not 32 hex characters, so a tampered database column cannot address anything outside the
 * recording directory it belongs to. Reusing it rather than assembling
 * `recordings/<id>/<name>` here is the whole security argument: there is one path builder for retained
 * recordings and this endpoint is not a second one.
 *
 * ## Why `retainedAudioPath` and never `storedAudioPath`
 *
 * `storedAudioPath` points into the queue's working tree, where a file is transient and is *moved* away
 * on completion. Serving from there would race the worker. `retainedAudioPath` is the copy that outlives
 * transcription, which is exactly the one a person means when they ask to hear the original.
 *
 * ## The ETag cannot be a content digest, unlike the generated file
 *
 * A generated rendition stores the transcript digest its bytes were made from, so its ETag is free. No
 * such column exists for an upload, and hashing a multi-megabyte file on every request to produce one
 * would cost more than the caching saves. Size and mtime are used instead: both change whenever the
 * bytes do, and the recording is never rewritten in place — the upload path writes once and the
 * retention path only ever deletes. The tag is marked weak (`W/`) because it is a validator, not a
 * checksum, and claiming otherwise would be a promise this cannot keep.
 *
 * ## Authorisation
 *
 * The administrator gate is route-group middleware, as everywhere else in this feature. Past it, every
 * failure — unknown id, a job that retained nothing, a filename that is not ours, a file since deleted —
 * collapses to the same 404 through {@see JobPageGuard}, so a response cannot be used to learn which of
 * those was true.
 */
final readonly class Action
{
    /**
     * Content types for the extensions {@see \App\AudioToText\Application\AudioUploadValidator::EXTENSIONS}
     * permits, and nothing else.
     *
     * An allow-list rather than a lookup of the stored name's extension, because this string becomes a
     * `Content-Type` header and `nosniff` is set application-wide: an extension with no entry here is
     * served as `application/octet-stream`, which a browser will download but never execute or guess at.
     */
    private const CONTENT_TYPES = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'webm' => 'audio/webm',
    ];

    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private QueuedAudioStorage $storage,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId, ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $path = $this->storage->retainedPathFor($job->publicId, $job->retainedAudioPath);
        $size = $path === null ? false : filesize($path);

        if ($path === null || $size === false || $size <= 0) {
            return $this->guard->notFound();
        }

        $contentType = $this->contentType((string) $job->retainedAudioPath);
        $etag = sprintf('W/"%d-%d"', $size, (int) @filemtime($path));

        if ($this->matchesEtag($request, $etag)) {
            return $this->headers($this->responseFactory->createResponse(304), $etag, $contentType)
                ->withHeader('Content-Length', '0');
        }

        $stream = Utils::streamFor(Utils::tryFopen($path, 'rb'));
        $range = $this->range($request, $size);

        if ($range === null) {
            return $this->headers($this->responseFactory->createResponse(200), $etag, $contentType)
                ->withHeader('Content-Length', (string) $size)
                ->withHeader('Content-Disposition', $this->disposition($request, $job->originalFilename))
                ->withBody($stream);
        }

        if ($range === false) {
            // Unsatisfiable. RFC 9110 requires the total length so the client can correct itself rather
            // than guess.
            return $this->headers($this->responseFactory->createResponse(416), $etag, $contentType)
                ->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return $this->headers($this->responseFactory->createResponse(206), $etag, $contentType)
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size))
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Disposition', $this->disposition($request, $job->originalFilename))
            // `LimitStream`, not `fseek`: `SapiEmitter::emitBody()` rewinds the body before emitting, so
            // a seeked handle would be returned to byte zero and streamed to the end — the wrong bytes,
            // at a length contradicting the header. `LimitStream::rewind()` returns to the range offset.
            ->withBody(new LimitStream($stream, $length, $start));
    }

    /**
     * Headers every response carries, whatever its status.
     *
     * `Accept-Ranges` is on the 200 as well as the 206 deliberately: a client learns ranges are available
     * from the full response, and one that never sees it will not ask. Safari opens an `<audio>` element
     * with `Range: bytes=0-1` and will not play a source that answers 200, so this is not optional.
     */
    private function headers(ResponseInterface $response, string $etag, string $contentType): ResponseInterface
    {
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('ETag', $etag)
            // Revalidate every time, but let the browser keep its copy — which is what makes seeking in
            // a long recording cost one 304 rather than another download. Never `public`: this is a
            // customer's call.
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function matchesEtag(ServerRequestInterface $request, string $etag): bool
    {
        $header = $request->getHeaderLine('If-None-Match');

        return $header !== '' && ($header === $etag || $header === '*');
    }

    /**
     * Parse a single byte range.
     *
     * Returns `null` for "no range, send everything", `false` for unsatisfiable, or the resolved
     * inclusive `[start, end]`. One range only: multiple ranges need a multipart response that nothing
     * asking for audio sends, and answering the whole file is an explicitly permitted response to a
     * range request.
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

    /** The stored extension decides, never the client-supplied original name. */
    private function contentType(string $retainedName): string
    {
        $extension = strtolower(pathinfo($retainedName, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * `inline` so the player can use it, `attachment` only when Download was pressed.
     *
     * The name shown to the person is their own upload's, but folded through a character class that
     * cannot contain a quote, a semicolon or a newline — so no part of it can escape the header it sits
     * in. The extension comes from the stored name, so it always matches the bytes.
     */
    private function disposition(ServerRequestInterface $request, string $originalFilename): string
    {
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalFilename, PATHINFO_FILENAME));
        $stem = trim((string) $stem, '-');
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'audio';

        $name = sprintf('%s.%s', $stem === '' ? 'recording' : $stem, $extension);
        $wantsDownload = ($request->getQueryParams()['download'] ?? null) !== null;

        return sprintf('%s; filename="%s"', $wantsDownload ? 'attachment' : 'inline', $name);
    }
}
