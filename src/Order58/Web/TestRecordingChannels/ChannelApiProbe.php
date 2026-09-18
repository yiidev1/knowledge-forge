<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use JsonException;
use Psr\Http\Message\ResponseInterface;

use function is_array;
use function json_decode;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The only outbound call this tool makes, and nothing else.
 *
 * A plain unauthenticated GET against the external recording host. **No Bearer token and no
 * Authorization header is sent** — the endpoint is gated by an IP allowlist, not by a credential, and
 * inventing a header would only obscure what the provider actually says. Nothing is written, queued or
 * saved: bodies are read into memory in bounded chunks or streamed straight through, and are gone when
 * the request ends.
 *
 * Settings, `http_errors => false`, redirect-following and streaming all mirror the existing, working
 * `RecordingApiProbe`. That file is frozen and is deliberately **not** imported: reaching into a
 * directory guarded by an isolation test, to reuse it from a new tool, would couple the two and put the
 * working one at risk for no behavioural gain.
 *
 * The constructor arguments exist for tests, which drive this against a Guzzle mock handler rather than
 * the network. Production resolves it with no arguments and gets the real host.
 */
final readonly class ChannelApiProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 60;

    /**
     * How much of a body is kept for inspection.
     *
     * A recording is megabytes that must never reach the HTML. This is enough to check a RIFF/WAVE
     * header and to show an error page, and the rest is counted and dropped.
     */
    private const SAMPLE_BYTES = 8192;

    private const READ_CHUNK_BYTES = 8192;

    public function __construct(
        private ?ClientInterface $httpClient = null,
        private ChannelRequestMapping $mapping = new ChannelRequestMapping(),
    ) {}

    public function urlFor(ChannelRecordingRequest $request, RecordingChannel $channel): string
    {
        return $this->mapping->urlFor($request, $channel);
    }

    public function mapping(): ChannelRequestMapping
    {
        return $this->mapping;
    }

    /**
     * The list of recent calls for an account — a CONFIRMED endpoint, unlike the channel mapping.
     *
     * The body is JSON of a few kilobytes rather than a recording, so it is read whole (still bounded by
     * the same sampling cap) and decoded. Decoding is best-effort: a body that is not a list of call
     * objects yields no rows and the raw response is printed instead, which is more useful than an
     * exception.
     */
    public function latestCalls(LatestCallsRequest $request): LatestCallsResult
    {
        $url = $this->mapping->latestCallsUrl($request->accountId, $request->limit);
        $response = $this->send($url);

        [$body, ] = $this->readBounded($response);
        $status = $response->getStatusCode();

        $diagnosis = ChannelDiagnosis::forJson($status, $body);
        $calls = [];

        if ($diagnosis->isSuccess()) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $calls = CallSummary::fromDecoded($decoded);

                // A valid JSON body that is not a list of calls, and an account with genuinely no recent
                // calls, are different problems — so they get different sentences.
                $diagnosis = $calls === []
                    ? (is_array($decoded) ? ChannelDiagnosis::noCalls() : ChannelDiagnosis::invalidJson('the body was not a list of calls'))
                    : $diagnosis;
            } catch (JsonException $e) {
                $diagnosis = ChannelDiagnosis::invalidJson($e->getMessage());
            }
        }

        return new LatestCallsResult(
            url: $url,
            status: $status,
            reason: $response->getReasonPhrase(),
            calls: $calls,
            diagnosis: $diagnosis,
            rawBody: $body,
        );
    }

    /**
     * Fetch as a diagnostic: the body is measured and sampled, never kept whole.
     *
     * @throws UnconfirmedChannelMapping when the channel's request format is not resolved
     */
    public function inspect(ChannelRecordingRequest $request, RecordingChannel $channel): ChannelProbeResult
    {
        $url = $this->mapping->urlFor($request, $channel);
        $response = $this->send($url);

        [$sample, $bytes] = $this->readBounded($response);

        $contentType = $response->getHeaderLine('Content-Type');

        return new ChannelProbeResult(
            url: $url,
            status: $response->getStatusCode(),
            reason: $response->getReasonPhrase(),
            contentType: $contentType,
            contentDisposition: $response->getHeaderLine('Content-Disposition'),
            contentLength: $response->hasHeader('Content-Length') ? $response->getHeaderLine('Content-Length') : null,
            bytes: $bytes,
            sample: $sample,
            wav: WavSignature::inspect($sample, $bytes),
            diagnosis: ChannelDiagnosis::fromResponse($response->getStatusCode(), $contentType, $sample, $bytes),
        );
    }

    /**
     * Fetch as a download: the same call to the same URL, with the body left unread so the caller can
     * stream it straight to the browser instead of buffering a recording in memory.
     *
     * @throws UnconfirmedChannelMapping when the channel's request format is not resolved
     */
    public function open(ChannelRecordingRequest $request, RecordingChannel $channel): ResponseInterface
    {
        return $this->send($this->mapping->urlFor($request, $channel));
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

    /**
     * Reads the whole body to get an honest byte count, but keeps only the first {@see SAMPLE_BYTES}.
     *
     * The honest count matters: it is what catches a truncated download, which has a perfectly valid
     * header and is the failure most likely to be mistaken for success.
     *
     * @return array{0: string, 1: int} sample, total bytes
     */
    private function readBounded(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $sample = '';
        $bytes = 0;

        while (!$body->eof()) {
            $chunk = $body->read(self::READ_CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $bytes += strlen($chunk);
            $kept = strlen($sample);

            if ($kept < self::SAMPLE_BYTES) {
                $sample .= substr($chunk, 0, self::SAMPLE_BYTES - $kept);
            }
        }

        return [$sample, $bytes];
    }
}
