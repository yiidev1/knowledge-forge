<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;

use function http_build_query;
use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * The two outbound calls this test page can make, and nothing else.
 *
 * Both are plain unauthenticated GETs against the external recording host. **No Bearer token and no
 * Authorization header is sent** — the endpoints are gated by an IP allowlist, not by a credential, and
 * inventing a header would only obscure what the provider actually says. Nothing is written, queued or
 * saved: the response body is read into memory, measured, and discarded when the request ends.
 *
 * Why a local Guzzle instance rather than the shared {@see \App\Order58\Contract\Order58ClientInterface}:
 * this host is not `ORDER58_API_BASE_URL`, needs no token, and must surface raw non-2xx bodies instead of
 * mapped exceptions. Wiring it through the shared client would have meant changing production sync code.
 *
 * `http_errors => false` follows the project convention — a 403 is a response to display, not a throw.
 * Redirects *are* followed here (unlike PSR-18's `sendRequest`, which forces them off), because a
 * recording fetch that hands back a 302 to storage should be followed to the audio it points at.
 */
final readonly class RecordingApiProbe
{
    private const BASE_URL = 'https://order58.xrainbow.com/api/external/recording';

    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 60;

    /**
     * How much of a body is kept for display. A call list is a few KB; a recording is megabytes we must
     * never paste into HTML, so the rest is counted and dropped.
     */
    private const PREVIEW_BYTES = 65536;

    private const READ_CHUNK_BYTES = 8192;

    /** API 1 — the list of recent calls for an account. */
    public function fetchLatestCalls(int $accountId, int $limit): ProbeResult
    {
        return $this->get(sprintf(
            '%s/%d/latest-calls?%s',
            self::BASE_URL,
            $accountId,
            http_build_query(['limit' => $limit]),
        ));
    }

    /**
     * API 2 — the recording for one call session. Every segment and parameter is encoded rather than
     * concatenated, so a value carrying `/`, `&` or `?` cannot reshape the URL.
     */
    public function fetchRecording(string $callSessionId, string $time, string $company, string $name): ProbeResult
    {
        return $this->get(sprintf(
            '%s/fetch/%s?%s',
            self::BASE_URL,
            rawurlencode($callSessionId),
            http_build_query(['time' => $time, 'company' => $company, 'name' => $name]),
        ));
    }

    private function get(string $url): ProbeResult
    {
        $client = new GuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        $response = $client->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
            'allow_redirects' => true,
            // Read the body ourselves in bounded chunks instead of letting Guzzle buffer a whole recording.
            'stream' => true,
        ]);

        [$preview, $bytes, $truncated] = $this->readBounded($response);

        $contentType = $response->getHeaderLine('Content-Type');

        return new ProbeResult(
            url: $url,
            status: $response->getStatusCode(),
            reason: $response->getReasonPhrase(),
            headers: $response->getHeaders(),
            contentType: $contentType,
            contentLength: $response->hasHeader('Content-Length') ? $response->getHeaderLine('Content-Length') : null,
            bytes: $bytes,
            preview: $preview,
            truncated: $truncated,
            isBinary: $this->looksBinary($contentType, $preview),
        );
    }

    /**
     * Reads the whole body to get an honest byte count, but keeps only the first {@see PREVIEW_BYTES}.
     *
     * @return array{0: string, 1: int, 2: bool} preview, total bytes, whether the preview was cut short
     */
    private function readBounded(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $preview = '';
        $bytes = 0;

        while (!$body->eof()) {
            $chunk = $body->read(self::READ_CHUNK_BYTES);
            if ($chunk === '') {
                break;
            }

            $bytes += strlen($chunk);
            $kept = strlen($preview);
            if ($kept < self::PREVIEW_BYTES) {
                $preview .= substr($chunk, 0, self::PREVIEW_BYTES - $kept);
            }
        }

        return [$preview, $bytes, $bytes > strlen($preview)];
    }

    /**
     * Content-Type decides when it says something definite; a NUL byte settles the rest. Getting this
     * wrong in the safe direction only costs a preview — the byte count and headers are shown either way.
     */
    private function looksBinary(string $contentType, string $preview): bool
    {
        $type = strtolower($contentType);

        if ($type !== '') {
            if (
                str_starts_with($type, 'text/')
                || str_contains($type, 'json')
                || str_contains($type, 'xml')
                || str_contains($type, 'html')
            ) {
                return false;
            }

            if (
                str_starts_with($type, 'audio/')
                || str_starts_with($type, 'video/')
                || str_starts_with($type, 'image/')
                || str_contains($type, 'octet-stream')
            ) {
                return true;
            }
        }

        return $preview !== '' && str_contains($preview, "\0");
    }
}
