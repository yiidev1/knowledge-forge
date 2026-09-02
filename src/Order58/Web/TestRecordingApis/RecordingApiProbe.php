<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

use function http_build_query;
use function rawurlencode;
use function sprintf;
use function strlen;
use function substr;

/**
 * The outbound calls this test tool can make, and nothing else.
 *
 * All of them are plain unauthenticated GETs against the external recording host. **No Bearer token and
 * no Authorization header is sent** — the endpoints are gated by an IP allowlist, not by a credential,
 * and inventing a header would only obscure what the provider actually says. Nothing is written, queued
 * or saved: bodies are read into memory or streamed straight through, and are gone when the request ends.
 *
 * Why a local Guzzle instance rather than the shared {@see \App\Order58\Contract\Order58ClientInterface}:
 * this host is not `ORDER58_API_BASE_URL`, needs no token, and must surface raw non-2xx bodies instead of
 * mapped exceptions. Wiring it through the shared client would have meant changing production sync code.
 *
 * `http_errors => false` follows the project convention — a 403 is a response to inspect, not a throw.
 * Redirects *are* followed here (unlike PSR-18's `sendRequest`, which forces them off), because a
 * recording fetch that answers with a 302 to storage should be followed to the audio it points at.
 *
 * The constructor arguments exist for tests, which drive this against a Guzzle mock handler rather than
 * the network. Production resolves it with no arguments and gets the real host.
 */
final readonly class RecordingApiProbe
{
    private const BASE_URL = 'https://order58.xrainbow.com/api/external/recording';

    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 60;

    /**
     * How much of a body the diagnostic page keeps for display. A call list is a few KB; a recording is
     * megabytes we must never paste into HTML, so the rest is counted and dropped.
     */
    private const PREVIEW_BYTES = 65536;

    private const READ_CHUNK_BYTES = 8192;

    public function __construct(
        private ?ClientInterface $httpClient = null,
        private string $baseUrl = self::BASE_URL,
    ) {}

    /** API 1 — the list of recent calls for an account. */
    public function fetchLatestCalls(int $accountId, int $limit): ProbeResult
    {
        $url = sprintf(
            '%s/%d/latest-calls?%s',
            $this->baseUrl,
            $accountId,
            http_build_query(['limit' => $limit]),
        );

        return $this->summarize($url, $this->send($url));
    }

    /** API 2, as a diagnostic: the body is measured and sampled, never kept whole. */
    public function fetchRecording(string $callSessionId, string $time, string $company, string $name): ProbeResult
    {
        $url = $this->recordingUrl($callSessionId, $time, $company, $name);

        return $this->summarize($url, $this->send($url));
    }

    /**
     * API 2, as a download: the same call to the same URL, with the body left unread so the caller can
     * stream it straight to the browser instead of buffering a recording in memory.
     */
    public function openRecording(string $callSessionId, string $time, string $company, string $name): ResponseInterface
    {
        return $this->send($this->recordingUrl($callSessionId, $time, $company, $name));
    }

    public function recordingUrl(string $callSessionId, string $time, string $company, string $name): string
    {
        // Every segment and parameter is encoded rather than concatenated, so a value carrying `/`, `&`
        // or `?` cannot reshape the URL.
        return sprintf(
            '%s/fetch/%s?%s',
            $this->baseUrl,
            rawurlencode($callSessionId),
            http_build_query(['time' => $time, 'company' => $company, 'name' => $name]),
        );
    }

    private function send(string $url): ResponseInterface
    {
        $client = $this->httpClient ?? new GuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        return $client->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
            'allow_redirects' => true,
            // Read the body ourselves in bounded chunks instead of letting Guzzle buffer a whole recording.
            'stream' => true,
        ]);
    }

    private function summarize(string $url, ResponseInterface $response): ProbeResult
    {
        [$preview, $bytes, $truncated] = $this->readBounded($response);

        $contentType = $response->getHeaderLine('Content-Type');

        return new ProbeResult(
            url: $url,
            status: $response->getStatusCode(),
            reason: $response->getReasonPhrase(),
            headers: $response->getHeaders(),
            contentType: $contentType,
            contentDisposition: $response->getHeaderLine('Content-Disposition'),
            contentLength: $response->hasHeader('Content-Length') ? $response->getHeaderLine('Content-Length') : null,
            bytes: $bytes,
            preview: $preview,
            truncated: $truncated,
            isBinary: BodyKind::isBinary($contentType, $preview),
        );
    }

    /**
     * Reads the whole body to get an honest byte count, but keeps only the first {@see PREVIEW_BYTES}.
     *
     * @return array{0: string, 1: int, 2: bool} sample, total bytes, whether the sample was cut short
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
}
