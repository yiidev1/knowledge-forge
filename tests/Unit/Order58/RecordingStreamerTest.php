<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\RecordingStream\RecordingFetchFailure;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Application\RecordingStream\RecordingStreamGate;
use App\Order58\Application\RecordingStream\RecordingStreamSettings;
use Codeception\Test\Unit;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

use function fclose;
use function file_get_contents;
use function flock;
use function fopen;
use function is_dir;
use function mkdir;
use function pack;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const LOCK_EX;
use const LOCK_NB;

/**
 * Fetching one recording on demand, against a scripted provider. **No test here opens a socket.**
 */
final class RecordingStreamerTest extends Unit
{
    private const URL = 'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
        . '?time=2026-07-29&company=SWCC&name=15594361-15550001111-21403649';

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    private string $locks;

    protected function _before(): void
    {
        $this->history = [];
        $this->locks = sys_get_temp_dir() . '/kf-stream-test-' . uniqid();

        if (!is_dir($this->locks)) {
            mkdir($this->locks, 0700, true);
        }
    }

    public function testAWavIsSpooledWhole(): void
    {
        $wav = $this->wav(5000);
        $fetch = $this->streamer(new Response(200, ['Content-Length' => (string) strlen($wav)], $wav))->fetch(self::URL);

        self::assertTrue($fetch->isOk());
        self::assertSame(strlen($wav), $fetch->size);
        self::assertSame($wav, (string) $fetch->body);
        self::assertCount(1, $this->history);
    }

    /** The provider's real Content-Type is octet-stream; the bytes decide, not the header. */
    public function testTheBytesDecideNotTheContentType(): void
    {
        $wav = $this->wav(200);
        $fetch = $this->streamer(new Response(200, ['Content-Type' => 'application/octet-stream'], $wav))->fetch(self::URL);

        self::assertTrue($fetch->isOk());
    }

    /** An error page answered with 200 must never be served as audio. */
    public function testAnHtmlBodyIsNotAudio(): void
    {
        $fetch = $this->streamer(new Response(200, [], '<html><body>Something went wrong</body></html>'))->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::NotAudio, $fetch->failure);
        self::assertNull($fetch->body);
    }

    /**
     * @dataProvider refusals
     */
    public function testEachRefusalIsNamed(int $status, string $body, RecordingFetchFailure $expected, string $detail): void
    {
        $fetch = $this->streamer(new Response($status, [], $body))->fetch(self::URL);

        self::assertSame($expected, $fetch->failure);
        self::assertSame($status, $fetch->upstreamStatus);
        self::assertSame($detail, $fetch->detail);
    }

    /** @return array<string, array{int, string, RecordingFetchFailure, string}> */
    public static function refusals(): array
    {
        return [
            'not found' => [404, '', RecordingFetchFailure::NotFound, ''],
            'gone' => [410, '', RecordingFetchFailure::NotFound, ''],
            'allowlist' => [403, '403 Forbidden - IP not authorized: 203.0.113.9', RecordingFetchFailure::Forbidden, 'ip-not-allowlisted'],
            'other forbidden' => [403, 'nope', RecordingFetchFailure::Forbidden, ''],
            'unauthorized' => [401, '', RecordingFetchFailure::Forbidden, ''],
            'server error' => [500, 'oops', RecordingFetchFailure::UpstreamError, ''],
            'bad gateway' => [502, '', RecordingFetchFailure::UpstreamError, ''],
        ];
    }

    public function testRateLimitingCarriesTheProvidersWait(): void
    {
        $fetch = $this->streamer(new Response(429, ['Retry-After' => '120'], ''))->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::RateLimited, $fetch->failure);
        self::assertSame(120, $fetch->retryAfterSeconds);
    }

    /** A redirect is an answer, never a second request — the one way a checked link could move. */
    public function testARedirectIsNotFollowed(): void
    {
        $fetch = $this->streamer(
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/'], ''),
            new Response(200, [], $this->wav(100)),
        )->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::UpstreamError, $fetch->failure);
        self::assertCount(1, $this->history, 'exactly one request was made');
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testTheRequestIsAPlainGetOfTheStoredLink(): void
    {
        $this->streamer(new Response(200, [], $this->wav(100)))->fetch(self::URL);

        self::assertSame('GET', $this->history[0]['request']->getMethod());
        self::assertSame(self::URL, (string) $this->history[0]['request']->getUri(), 'the exact URL, not rebuilt');
        self::assertTrue($this->history[0]['options']['stream']);
    }

    public function testAnUnreachableProviderIsNamedWithoutItsMessage(): void
    {
        $fetch = $this->streamer(new ConnectException(
            'cURL error 7: Failed to connect to ' . self::URL,
            new Request('GET', self::URL),
        ))->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::Unreachable, $fetch->failure);
        self::assertSame(ConnectException::class, $fetch->detail, 'the class, never the message with the URL');
    }

    public function testADeclaredSizeOverTheCeilingIsRefusedBeforeReading(): void
    {
        $fetch = $this->streamer(new Response(200, [
            'Content-Length' => (string) (RecordingStreamer::MAX_BYTES + 1),
        ], $this->wav(100)))->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::TooLarge, $fetch->failure);
    }

    public function testABodyShorterThanItsContentLengthIsTruncated(): void
    {
        $fetch = $this->streamer(new Response(200, ['Content-Length' => '10000'], $this->wav(500)))->fetch(self::URL);

        self::assertSame(RecordingFetchFailure::Truncated, $fetch->failure);
    }

    /** A link the policy refuses is never requested at all. */
    public function testARefusedLinkMakesNoRequest(): void
    {
        $fetch = $this->streamer(new Response(200, [], $this->wav(100)))
            ->fetch('https://example.com/api/external/recording/fetch/1');

        self::assertSame(RecordingFetchFailure::Refused, $fetch->failure);
        self::assertCount(0, $this->history);
    }

    /** Every slot taken: the fetch waits briefly, then reports busy — and asks the provider nothing. */
    public function testWhenEverySlotIsTakenTheAnswerIsBusy(): void
    {
        $held = fopen($this->locks . '/order58-recording-stream-0.lock', 'c');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX | LOCK_NB));

        try {
            $fetch = $this->streamerWith(1, new Response(200, [], $this->wav(100)))->fetch(self::URL);

            self::assertSame(RecordingFetchFailure::Busy, $fetch->failure);
            self::assertSame(3, $fetch->retryAfterSeconds);
            self::assertCount(0, $this->history);
        } finally {
            fclose($held);
        }
    }

    /** The slot is given back, so the next listener is not refused. */
    public function testASlotIsReleasedAfterEachFetch(): void
    {
        $streamer = $this->streamerWith(
            1,
            new Response(200, [], $this->wav(100)),
            new Response(200, [], $this->wav(100)),
        );

        self::assertTrue($streamer->fetch(self::URL)->isOk());
        self::assertTrue($streamer->fetch(self::URL)->isOk());
    }

    /** Fixture mode answers from local files and never builds an HTTP request. */
    public function testFixtureModeServesALocalFileWithoutAnyRequest(): void
    {
        $directory = __DIR__ . '/../../_data/call-recordings';
        $streamer = new RecordingStreamer(
            new RecordingStreamSettings(1, true, $directory, $this->locks),
            new RecordingStreamGate(new RecordingStreamSettings(1, true, $directory, $this->locks), new NullLogger()),
            $this->client([]),
        );

        $fetch = $streamer->fetch(self::URL);

        self::assertTrue($fetch->isOk());
        self::assertSame((string) file_get_contents($directory . '/default.wav'), (string) $fetch->body);
        self::assertCount(0, $this->history);

        $gone = $streamer->fetch('https://order58.xrainbow.com/api/external/recording/fetch/919900404?time=x');
        self::assertSame(RecordingFetchFailure::NotFound, $gone->failure);
    }

    private function streamer(Response|ConnectException ...$answers): RecordingStreamer
    {
        return $this->streamerWith(2, ...$answers);
    }

    private function streamerWith(int $maxConcurrent, Response|ConnectException ...$answers): RecordingStreamer
    {
        $settings = new RecordingStreamSettings($maxConcurrent, false, '', $this->locks);

        return new RecordingStreamer(
            $settings,
            new RecordingStreamGate($settings, new NullLogger()),
            $this->client($answers),
        );
    }

    /** @param list<Response|ConnectException> $answers */
    private function client(array $answers): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /** A minimal, complete RIFF/WAVE file with this many bytes of silence. */
    private function wav(int $dataBytes): string
    {
        return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', $dataBytes) . str_repeat("\0", $dataBytes);
    }
}
