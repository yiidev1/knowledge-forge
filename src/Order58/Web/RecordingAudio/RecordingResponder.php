<?php

declare(strict_types=1);

namespace App\Order58\Web\RecordingAudio;

use GuzzleHttp\Psr7\LimitStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

use function max;
use function min;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * A fetched recording as an HTTP response: the whole file, one byte range of it, or a refusal.
 *
 * Shared by the Order API viewer and the order pages, so both answer seeking, downloading and errors the
 * same way. `Accept-Ranges` is on every success, because a media element asks with `Range: bytes=0-`
 * and will not seek in a source that answers otherwise — and the provider itself does not do ranges,
 * so they are answered here from the request-scoped spool.
 */
final readonly class RecordingResponder
{
    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    public function respond(
        StreamInterface $body,
        int $size,
        ServerRequestInterface $request,
        bool $download,
        string $fileName,
    ): ResponseInterface {
        $range = $this->range($request->getHeaderLine('Range'), $size);

        if ($range === false) {
            return $this->headers($this->responseFactory->createResponse(416), $download, $fileName)
                ->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        if ($range === null) {
            return $this->headers($this->responseFactory->createResponse(200), $download, $fileName)
                ->withHeader('Content-Length', (string) $size)
                ->withBody($body);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return $this->headers($this->responseFactory->createResponse(206), $download, $fileName)
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size))
            ->withHeader('Content-Length', (string) $length)
            ->withBody(new LimitStream($body, $length, $start));
    }

    /**
     * Plain text, so a failure can never be saved by a browser as though it were audio.
     *
     * Never carries the provider's URL or body; the sentence is this application's own.
     */
    public function problem(int $status, string $text, ?int $retryAfterSeconds = null): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private');

        if ($retryAfterSeconds !== null) {
            $response = $response->withHeader('Retry-After', (string) $retryAfterSeconds);
        }

        $response->getBody()->write($text);

        return $response;
    }

    private function headers(ResponseInterface $response, bool $download, string $fileName): ResponseInterface
    {
        // Letters, digits, dot, dash and underscore only: the name cannot carry a quote or a separator.
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $fileName);

        return $response
            // The body was confirmed to be RIFF/WAVE before this is reached, so this is the real type.
            ->withHeader('Content-Type', 'audio/wav')
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // A customer's voice: no shared cache, and no copy kept by the browser's cache either.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Content-Disposition', sprintf(
                '%s; filename="%s"',
                $download ? 'attachment' : 'inline',
                $safe === '' ? 'recording.wav' : $safe,
            ));
    }

    /**
     * One byte range, or null for the whole file, or false when it cannot be satisfied.
     *
     * A malformed header, or several ranges at once, is answered with the whole file — what RFC 9110
     * allows a server to do — rather than refused.
     *
     * @return array{0: int, 1: int}|false|null
     */
    private function range(string $header, int $size): array|false|null
    {
        $header = trim($header);

        if ($header === '' || preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $m) !== 1) {
            return null;
        }

        if ($m[1] === '' && $m[2] === '') {
            return null;
        }

        if ($size <= 0) {
            return false;
        }

        $last = $size - 1;

        // A suffix range: the final N bytes.
        if ($m[1] === '') {
            $suffix = (int) $m[2];

            return $suffix === 0 ? false : [max(0, $size - $suffix), $last];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $last : min((int) $m[2], $last);

        if ($start > $last || $start > $end) {
            return false;
        }

        return [$start, $end];
    }
}
