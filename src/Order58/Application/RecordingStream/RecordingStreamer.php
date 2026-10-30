<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

use App\Integration\Order58Recording\RecordingUrlPolicy;
use App\Integration\Order58Recording\WavSignature;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

use function fclose;
use function file_get_contents;
use function filesize;
use function fopen;
use function fwrite;
use function is_file;
use function is_readable;
use function is_resource;
use function microtime;
use function min;
use function preg_match;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * Fetches one recording from the provider, on demand, for one response — and keeps nothing.
 *
 * This is the playback approach the Order API viewer proved on the production server, made reusable:
 * the browser never sees the provider's URL, this server makes the request from its allowlisted
 * address, the body is confirmed to be RIFF/WAVE before anything calls it audio, and redirects are not
 * followed. What changed from the viewer's first version is memory: it read the whole file into a PHP
 * string and copied it twice more to answer a range, so one long recording could exhaust a worker. Here
 * the body is spooled in chunks into a request-scoped temporary stream — memory up to a megabyte, an
 * anonymous temp file past that, gone when the request ends — and a range is a window onto it.
 *
 * ## Why spool rather than pipe straight through
 *
 * The provider ignores `Range` (measured on 27 October 2026: it answers 200 with the whole file and no
 * `Accept-Ranges`), so seeking can only be offered by this server. A complete spool gives an exact
 * `Content-Length`, correct `206` answers and a WAV check on the first bytes, from one upstream request
 * per press of Play. Piping instead would gain nothing in practice: the application's response emitter
 * buffers up to eight megabytes before it writes, which is larger than a phone call's WAV.
 *
 * ## What is never done here
 *
 * No file is written anywhere a later request could find it, no database row is touched, and neither
 * the URL nor the provider's body is logged or returned — the URL carries a customer phone number.
 */
final readonly class RecordingStreamer
{
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /** Silence on an open connection for this long ends the fetch. */
    private const READ_TIMEOUT_SECONDS = 30;

    /** The whole transfer, however steadily it trickles. */
    private const DEADLINE_SECONDS = 120;

    /** Far above any phone call: 8 kHz mono is about a megabyte a minute. */
    public const MAX_BYTES = 209_715_200;

    private const CHUNK_BYTES = 65_536;

    /** Kept in memory up to this; past it the spool moves to an anonymous temporary file. */
    private const SPOOL_MEMORY_BYTES = 1_048_576;

    /** Enough of the body to decide whether it is RIFF/WAVE. */
    private const PEEK_BYTES = 64;

    /** How much of a refusal is read to recognise the provider's allowlist wording, and no more. */
    private const ERROR_SAMPLE_BYTES = 512;

    public function __construct(
        private RecordingStreamSettings $settings,
        private RecordingStreamGate $gate,
        private ?ClientInterface $httpClient = null,
    ) {}

    public function fetch(string $url): RecordingFetch
    {
        // Checked again here even though every stored link was checked when it was stored: a row
        // written by any other route must still not be able to point this server anywhere else.
        if (!RecordingUrlPolicy::accepts($url)) {
            return RecordingFetch::failed(RecordingFetchFailure::Refused);
        }

        if ($this->settings->fixture) {
            return $this->fromFixture($url);
        }

        $slot = $this->gate->acquire();

        if ($slot === null) {
            return RecordingFetch::failed(RecordingFetchFailure::Busy, null, 3);
        }

        try {
            return $this->download($url);
        } finally {
            $slot->release();
        }
    }

    /**
     * How long a recording is, asked of the provider without fetching the audio.
     *
     * The page's duration labels were learned by requesting the first 4 KB of each recording. The
     * provider does not honour `Range` — verified repeatedly against the live service — so every one
     * of those requests was answered with the ENTIRE file. Store 1731's eight labels cost 37,369,952
     * bytes on every uncached page load. A `HEAD` carries the same `Content-Length` having
     * transferred ZERO bytes, and all eight were checked this way against the durations already on
     * the page: every one matched to the second.
     *
     * It takes a gate slot like any fetch: a HEAD transfers nothing, but the provider still locates
     * the recording — observed at 0.7 to 2.6 seconds — so it is a request this server makes of
     * somebody else's service and queues with the rest.
     *
     * It never guesses. {@see RecordingMeasurement::fromBytes()} refuses a size that does not
     * produce a plausible call length, and a provider answering without a `Content-Length` is a
     * refusal too. Either way the caller is told nothing was learned and reads the real header
     * instead — the path it took before this existed. This can be slow; it cannot be wrong.
     */
    public function measure(string $url): RecordingMeasurement
    {
        if (!RecordingUrlPolicy::accepts($url)) {
            return RecordingMeasurement::failed(RecordingFetchFailure::Refused);
        }

        if ($this->settings->fixture) {
            return $this->measureFixture($url);
        }

        $slot = $this->gate->acquire();

        if ($slot === null) {
            return RecordingMeasurement::failed(RecordingFetchFailure::Busy);
        }

        try {
            $response = $this->client()->request('HEAD', $url, [
                'headers' => ['Accept' => '*/*'],
                'http_errors' => false,
                // The same rule the download follows: an accepted address must not be able to lead
                // somewhere unaccepted.
                'allow_redirects' => false,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::DEADLINE_SECONDS,
            ]);
        } catch (ConnectException) {
            return RecordingMeasurement::failed(RecordingFetchFailure::Unreachable);
        } catch (Throwable) {
            // The message is deliberately not kept: a transport message quotes the full URI, which
            // carries the customer's telephone number.
            return RecordingMeasurement::failed(RecordingFetchFailure::Unreachable);
        } finally {
            $slot->release();
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return RecordingMeasurement::failed(
                $status === 404 ? RecordingFetchFailure::NotFound : RecordingFetchFailure::UpstreamError,
            );
        }

        $declared = trim($response->getHeaderLine('Content-Length'));

        if (preg_match('/\A\d{1,15}\z/', $declared) !== 1) {
            // A HEAD without a length answers nothing. Not an error — simply no shortcut available.
            return RecordingMeasurement::failed(RecordingFetchFailure::UpstreamError);
        }

        $bytes = (int) $declared;

        return $bytes > self::MAX_BYTES
            ? RecordingMeasurement::failed(RecordingFetchFailure::TooLarge)
            : RecordingMeasurement::fromBytes($bytes);
    }

    /**
     * The fixture's size on disk, so a test deployment answers this without a socket.
     *
     * Mirrors {@see fromFixture()}'s own search order, `default.wav` included, so the two cannot
     * disagree about which file a session resolves to.
     */
    private function measureFixture(string $url): RecordingMeasurement
    {
        $session = (string) RecordingUrlPolicy::sessionIdOf($url);
        $directory = $this->settings->fixtureDirectory;

        foreach ([$directory . '/' . $session . '.wav', $directory . '/default.wav'] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $bytes = filesize($path);

            if ($bytes !== false && $bytes > 0) {
                return RecordingMeasurement::fromBytes($bytes);
            }
        }

        return RecordingMeasurement::failed(RecordingFetchFailure::NotFound);
    }

    private function download(string $url): RecordingFetch
    {
        $started = microtime(true);

        try {
            $response = $this->client()->request('GET', $url, [
                'headers' => ['Accept' => '*/*'],
                'http_errors' => false,
                // The one way an accepted address could still lead somewhere unaccepted.
                'allow_redirects' => false,
                'stream' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'read_timeout' => self::READ_TIMEOUT_SECONDS,
                'timeout' => self::DEADLINE_SECONDS,
            ]);
        } catch (ConnectException $e) {
            return RecordingFetch::failed(RecordingFetchFailure::Unreachable, null, null, $e::class);
        } catch (Throwable $e) {
            // The message is deliberately not kept: a transport message quotes the full URI.
            return RecordingFetch::failed(RecordingFetchFailure::Unreachable, null, null, $e::class);
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return $this->refusal($response, $status);
        }

        $declared = trim($response->getHeaderLine('Content-Length'));
        $expected = preg_match('/\A\d{1,15}\z/', $declared) === 1 ? (int) $declared : null;

        if ($expected !== null && $expected > self::MAX_BYTES) {
            return RecordingFetch::failed(RecordingFetchFailure::TooLarge, $status);
        }

        return $this->spool($response->getBody(), $status, $expected, $started);
    }

    /**
     * Copy the body into a request-scoped spool, checking it is a WAV file before going further.
     */
    private function spool(StreamInterface $body, int $status, ?int $expected, float $started): RecordingFetch
    {
        $spool = fopen('php://temp/maxmemory:' . self::SPOOL_MEMORY_BYTES, 'w+b');

        if (!is_resource($spool)) {
            return RecordingFetch::failed(RecordingFetchFailure::UpstreamError, $status, null, 'no spool');
        }

        $head = '';
        $bytes = 0;
        $checked = false;

        try {
            while (!$body->eof()) {
                if (microtime(true) - $started > self::DEADLINE_SECONDS) {
                    fclose($spool);

                    return RecordingFetch::failed(RecordingFetchFailure::TimedOut, $status);
                }

                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);

                if ($bytes > self::MAX_BYTES) {
                    fclose($spool);

                    return RecordingFetch::failed(RecordingFetchFailure::TooLarge, $status);
                }

                if (!$checked) {
                    $head .= $chunk;

                    if (strlen($head) < self::PEEK_BYTES) {
                        continue;
                    }

                    $checked = true;
                    $verdict = WavSignature::inspect($head, strlen($head));

                    if (!$verdict->isWav) {
                        fclose($spool);

                        return RecordingFetch::failed(RecordingFetchFailure::NotAudio, $status, null, $verdict->summary);
                    }

                    $chunk = $head;
                }

                if (fwrite($spool, $chunk) === false) {
                    fclose($spool);

                    return RecordingFetch::failed(RecordingFetchFailure::UpstreamError, $status, null, 'spool write');
                }
            }
        } catch (Throwable $e) {
            fclose($spool);

            // A stalled read surfaces here; the message is not kept, for the same reason as above.
            return RecordingFetch::failed(RecordingFetchFailure::TimedOut, $status, null, $e::class);
        }

        // A body shorter than the peek never reached the check above.
        if (!$checked) {
            $verdict = WavSignature::inspect($head, strlen($head));

            if (!$verdict->isWav) {
                fclose($spool);

                return RecordingFetch::failed(RecordingFetchFailure::NotAudio, $status, null, $verdict->summary);
            }

            fwrite($spool, $head);
        }

        if ($expected !== null && $bytes < $expected) {
            fclose($spool);

            return RecordingFetch::failed(RecordingFetchFailure::Truncated, $status);
        }

        $stream = Utils::streamFor($spool);
        $stream->rewind();

        return RecordingFetch::ok($stream, $bytes, $status);
    }

    private function refusal(ResponseInterface $response, int $status): RecordingFetch
    {
        // A small sample, only to tell the allowlist refusal apart from any other 403. Never kept.
        $sample = '';

        try {
            $sample = strtolower($response->getBody()->read(self::ERROR_SAMPLE_BYTES));
        } catch (Throwable) {
            // Nothing to classify with; the status alone still answers.
        }

        return match (true) {
            $status === 404, $status === 410 => RecordingFetch::failed(RecordingFetchFailure::NotFound, $status),
            $status === 401, $status === 403 => RecordingFetch::failed(
                RecordingFetchFailure::Forbidden,
                $status,
                null,
                str_contains($sample, 'not authori') || str_contains($sample, 'whitelist')
                    || str_contains($sample, 'allowlist') ? 'ip-not-allowlisted' : '',
            ),
            $status === 429 => RecordingFetch::failed(
                RecordingFetchFailure::RateLimited,
                $status,
                $this->retryAfter($response),
            ),
            default => RecordingFetch::failed(RecordingFetchFailure::UpstreamError, $status),
        };
    }

    /** `Retry-After` in seconds when the provider sent a plain number, capped at an hour. */
    private function retryAfter(ResponseInterface $response): ?int
    {
        $raw = trim($response->getHeaderLine('Retry-After'));

        if (preg_match('/\A\d{1,5}\z/', $raw) !== 1) {
            return null;
        }

        $seconds = (int) $raw;

        return $seconds > 0 ? min($seconds, 3600) : null;
    }

    /**
     * A local file instead of the provider. Development and test only — see the settings.
     *
     * `{session}.wav` if there is one, `{session}.status` (holding an HTTP status) to simulate a
     * refusal, otherwise `default.wav`, so every stored order plays something locally without a single
     * request leaving the machine.
     */
    private function fromFixture(string $url): RecordingFetch
    {
        $session = (string) RecordingUrlPolicy::sessionIdOf($url);
        $directory = $this->settings->fixtureDirectory;
        $status = $directory . '/' . $session . '.status';

        if (is_file($status) && is_readable($status)) {
            $code = (int) trim((string) file_get_contents($status));

            return $code >= 200 && $code < 300
                ? RecordingFetch::failed(RecordingFetchFailure::NotAudio, $code, null, 'fixture')
                : $this->refusal(new Response($code), $code);
        }

        foreach ([$directory . '/' . $session . '.wav', $directory . '/default.wav'] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $handle = fopen($path, 'rb');

            if (!is_resource($handle)) {
                continue;
            }

            return $this->spool(Utils::streamFor($handle), 200, null, microtime(true));
        }

        return RecordingFetch::failed(RecordingFetchFailure::NotFound, 404);
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ?? new GuzzleClient();
    }
}
