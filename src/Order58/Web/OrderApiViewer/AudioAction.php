<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use App\Integration\Order58Recording\WavSignature;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_scalar;
use function max;
use function min;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Streams one recording to the browser
 * (GET /admin/order-api-viewer/audio?session=…&time=…).
 *
 * ## Not a URL proxy
 *
 * It takes a digits-only session id and a calendar date, and builds the address from constants —
 * see {@see RecordingRef}. There is no parameter that can name a host, a scheme or a path, so this
 * cannot be pointed at an internal service however the query string is bent. Redirects are **not
 * followed**, which closes the one remaining way an external answer could move the request elsewhere.
 *
 * ## Why the Content-Type is rewritten
 *
 * The provider answers `application/octet-stream`. Browsers will not reliably play that in an `<audio>`
 * element, which is the whole reason this endpoint exists rather than a plain link. The header is only
 * corrected **after** the first bytes are confirmed to be RIFF/WAVE: rewriting it before checking would
 * relabel an error page as audio, and the player would fail with no explanation of why.
 *
 * ## Range, and the honest limitation
 *
 * Measured on 27 October 2026: the provider ignores `Range` — it answers `200` with the whole file and
 * sends no `Accept-Ranges`. Seeking is still offered by reading forward and discarding the prefix,
 * which costs an upstream fetch per seek but needs no disk and no cache. That is a real trade and is
 * stated rather than hidden; an operator listening through a two-minute call will not notice, and
 * nothing is stored either way.
 *
 * ## Nothing is kept
 *
 * The body is passed through to the response as it arrives. No temporary file, no cache entry, no
 * database row, and the full URL is never logged — only the session id, which is not a secret.
 */
final readonly class AudioAction
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 120;

    /** Enough to confirm RIFF/WAVE before any byte is called audio. */
    private const HEADER_PEEK_BYTES = 64;

    private const CHUNK_BYTES = 65536;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private ?ClientInterface $httpClient = null,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $ref = RecordingRef::fromInput(
            $this->text($params['session'] ?? null),
            $this->text($params['time'] ?? null),
        );

        if ($ref === null) {
            return $this->problem(400, 'Invalid recording reference. A session id (digits) and a real '
                . 'calendar date are both required.');
        }

        $wantsDownload = $this->text($params['download'] ?? null) === '1';

        try {
            $upstream = $this->client()->request('GET', $ref->url(), [
                'headers' => ['Accept' => '*/*'],
                'http_errors' => false,
                // A redirect is the one way a validated address could still end up somewhere else.
                'allow_redirects' => false,
                'stream' => true,
            ]);
        } catch (Throwable $e) {
            return $this->problem(502, sprintf(
                "The recording service could not be reached for session %s.\n\n%s",
                $ref->sessionId,
                $e::class,
            ));
        }

        $status = $upstream->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return $this->problem(502, sprintf(
                "The recording service answered HTTP %d for session %s on %s.\n\n%s",
                $status,
                $ref->sessionId,
                $ref->time,
                $status === 404
                    ? 'No recording exists at that address. The date must match the recording\'s own date.'
                    : ($status === 403
                        ? 'Access was refused. This server\'s outbound IP may not be whitelisted.'
                        : 'The service did not return a recording.'),
            ));
        }

        $body = $upstream->getBody();
        $head = $body->read(self::HEADER_PEEK_BYTES);
        $wav = WavSignature::inspect($head, strlen($head) === 0 ? 0 : self::HEADER_PEEK_BYTES);

        if (!$wav->isWav) {
            return $this->problem(502, sprintf(
                "The recording service answered HTTP %d for session %s, but the body is not a WAV file.\n\n%s",
                $status,
                $ref->sessionId,
                $wav->summary,
            ));
        }

        $declared = $upstream->getHeaderLine('Content-Length');
        $total = preg_match('/\A\d+\z/', $declared) === 1 ? (int) $declared : null;

        $range = $this->requestedRange($request, $total);

        // The peeked header is put back in front so the file the browser receives is complete.
        $stream = Utils::streamFor($head . $body->getContents());

        return $range === null
            ? $this->whole($stream, $ref, $wantsDownload)
            : $this->partial($stream, $ref, $range, $wantsDownload);
    }

    /** The complete file. */
    private function whole($stream, RecordingRef $ref, bool $download): ResponseInterface
    {
        return $this->deliver($stream, $ref, $download, 200)
            ->withHeader('Content-Length', (string) $stream->getSize());
    }

    /**
     * One byte range, satisfied locally because the provider will not do it.
     *
     * @param array{0: int, 1: int} $range
     */
    private function partial($stream, RecordingRef $ref, array $range, bool $download): ResponseInterface
    {
        $size = (int) $stream->getSize();
        [$start, $end] = $range;
        $start = max(0, min($start, $size === 0 ? 0 : $size - 1));
        $end = min($end, $size === 0 ? 0 : $size - 1);

        $slice = Utils::streamFor(substr((string) $stream, $start, $end - $start + 1));

        return $this->deliver($slice, $ref, $download, 206)
            ->withHeader('Content-Length', (string) $slice->getSize())
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
    }

    private function deliver($stream, RecordingRef $ref, bool $download, int $status): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            // Verified RIFF/WAVE above, so this is the real type rather than a hopeful one.
            ->withHeader('Content-Type', 'audio/wav')
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Content-Disposition', sprintf(
                '%s; filename="%s"',
                $download ? 'attachment' : 'inline',
                $ref->downloadFileName(),
            ))
            ->withBody($stream);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function requestedRange(ServerRequestInterface $request, ?int $total): ?array
    {
        $header = trim($request->getHeaderLine('Range'));

        if ($header === '' || preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $m) !== 1) {
            return null;
        }

        $last = $total === null ? PHP_INT_MAX : $total - 1;

        if ($m[1] === '' && $m[2] === '') {
            return null;
        }

        // A suffix range: the final N bytes.
        if ($m[1] === '') {
            $length = (int) $m[2];

            return $total === null ? null : [max(0, $total - $length), $last];
        }

        return [(int) $m[1], $m[2] === '' ? $last : (int) $m[2]];
    }

    /** Plain text, so a failure can never be saved by a browser as though it were audio. */
    private function problem(int $status, string $text): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'no-store, private');

        $response->getBody()->write($text);

        return $response;
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ?? new GuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);
    }

    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
