<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Ai\OpenAi\Client\SleeperInterface;
use App\Order58\Application\RecordingStream\RecordingDurations;
use App\Order58\Application\RecordingStream\RecordingDurationSettings;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Application\RecordingStream\RecordingStreamGate;
use App\Order58\Application\RecordingStream\RecordingStreamSettings;
use App\Order58\Domain\CallRecordings\RecordingDurationBacklog;
use App\Order58\Domain\CallRecordings\RecordingDurationRepositoryInterface;
use App\Order58\Domain\CallRecordings\RecordingLocation;
use App\Order58\Domain\CallRecordings\RecordingToMeasure;
use App\Shared\Domain\Clock\ClockInterface;
use Codeception\Test\Unit;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

use function array_slice;
use function count;
use function in_array;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Measuring each Case Order recording's length once, through the provider's HEAD answer, and never again.
 *
 * The provider is a Guzzle mock: every request it receives is in `$history`, so "no request was made" is
 * an assertion, not a hope. 16,000 bytes are one second after the 44-byte header (RecordingMeasurement).
 */
final class RecordingDurationsTest extends Unit
{
    private const URL = 'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
        . '?time=2026-07-29&company=SWCC&name=15594361-15550001111-21403649';

    /** @var list<array{request: Request, options: array<string, mixed>}> */
    private array $history = [];

    private string $locks;

    private InMemoryDurations $store;

    protected function _before(): void
    {
        $this->history = [];
        $this->store = new InMemoryDurations();
        $this->locks = sys_get_temp_dir() . '/kf-durations-test-' . uniqid();

        if (!is_dir($this->locks)) {
            mkdir($this->locks, 0700, true);
        }
    }

    // ---------------------------------------------------------------- the page's duration request

    /** A stored length is answered from the database: the provider is not asked at all. */
    public function testAStoredDurationIsAnsweredWithoutAnyRequest(): void
    {
        $seconds = $this->durations()->forLocation($this->location(123.5));

        self::assertSame(123.5, $seconds);
        self::assertCount(0, $this->history);
    }

    /** An unmeasured one is measured once, through a HEAD request, and the answer stored. */
    public function testAnUnmeasuredDurationIsMeasuredOnceAndStored(): void
    {
        $seconds = $this->durations(new Response(200, ['Content-Length' => (string) (44 + 16_000 * 114)]))
            ->forLocation($this->location(null));

        self::assertSame(114.0, $seconds);
        self::assertSame('HEAD', $this->history[0]['request']->getMethod());
        self::assertSame([7 => 114.0], $this->store->recorded);
    }

    /** A failed measurement stores nothing; the page falls back exactly as before. */
    public function testAFailedMeasurementStoresNothing(): void
    {
        self::assertNull($this->durations(new Response(403))->forLocation($this->location(null)));
        self::assertSame([], $this->store->recorded);
    }

    // ---------------------------------------------------------------- the background run

    public function testARunMeasuresEachDueRecordingOnce(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 0), new RecordingToMeasure(2, self::URL, 0)];

        $run = $this->durations(
            new Response(200, ['Content-Length' => (string) (44 + 16_000 * 60)]),
            new Response(200, ['Content-Length' => (string) (44 + 16_000 * 90)]),
        )->runBatch();

        self::assertSame(2, $run->measured);
        self::assertSame([1 => 60.0, 2 => 90.0], $this->store->recorded);
        self::assertCount(2, $this->history);
    }

    /** A row another run holds is skipped without a request. */
    public function testARowClaimedElsewhereIsNotMeasuredAgain(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 0)];
        $this->store->refuseClaims = [1];

        $run = $this->durations()->runBatch();

        self::assertSame(1, $run->skipped);
        self::assertCount(0, $this->history);
    }

    /** A transient failure is retried later with a doubling delay and counts as an attempt. */
    public function testATransientFailureIsRetriedWithBackoff(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 1)];

        $run = $this->durations(new Response(503))->runBatch();

        self::assertSame(1, $run->failed);
        self::assertTrue($this->store->failures[1]['counts']);
        // Second failure: 15 minutes doubled once.
        self::assertSame('2026-11-06 10:30:00', $this->store->failures[1]['retryAt']->format('Y-m-d H:i:s'));
    }

    /** After the last allowed attempt the recording is given up on, and left unmeasured (so: shown). */
    public function testTheLastAttemptGivesUp(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 4)];

        $run = $this->durations(new ConnectException('down', new Request('HEAD', self::URL)))->runBatch();

        self::assertSame(1, $run->abandoned);
        self::assertSame([1], $this->store->abandoned);
        self::assertSame([], $this->store->recorded);
    }

    /** Every playback slot taken by listeners: release the row unchanged and stop the run. */
    public function testBusyPlaybackSlotsEndTheRunWithoutUsingARetry(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 0), new RecordingToMeasure(2, self::URL, 0)];
        $settings = new RecordingStreamSettings(1, false, '', $this->locks);
        $gate = new RecordingStreamGate($settings, new NullLogger());
        $held = $gate->acquire();
        self::assertNotNull($held);

        try {
            $run = $this->durations()->runBatch();
        } finally {
            $held->release();
        }

        self::assertSame('playback slots busy', $run->stoppedBecause);
        self::assertFalse($this->store->failures[1]['counts']);
        self::assertArrayNotHasKey(2, $this->store->failures, 'the run stopped');
        self::assertCount(0, $this->history);
    }

    public function testTheBatchLimitIsHonoured(): void
    {
        $this->store->due = [new RecordingToMeasure(1, self::URL, 0), new RecordingToMeasure(2, self::URL, 0)];

        $this->durations(new Response(200, ['Content-Length' => (string) (44 + 16_000)]))->runBatch(1);

        self::assertSame(1, $this->store->lastLimit);
    }

    public function testBackoffDoublesUpToADay(): void
    {
        $durations = $this->durations();

        self::assertSame(15, $durations->retryMinutes(1));
        self::assertSame(30, $durations->retryMinutes(2));
        self::assertSame(60, $durations->retryMinutes(3));
        self::assertSame(1440, $durations->retryMinutes(20));
    }

    // ---------------------------------------------------------------- helpers

    private function durations(Response|ConnectException ...$answers): RecordingDurations
    {
        $settings = new RecordingStreamSettings(1, false, '', $this->locks);
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));

        return new RecordingDurations(
            new RecordingStreamer($settings, new RecordingStreamGate($settings, new NullLogger()), new Client(['handler' => $stack])),
            $this->store,
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-11-06 10:00:00+00:00');
                }
            },
            new class implements SleeperInterface {
                public function sleep(float $seconds): void {}
            },
            new RecordingDurationSettings(batch: 50, pauseMs: 0, runSeconds: 50, maxAttempts: 5, retryMinutes: 15),
        );
    }

    private function location(?float $duration): RecordingLocation
    {
        return new RecordingLocation(7, 1731, 15275271, '21403649', 0, self::URL, $duration);
    }
}

/** The repository, in memory, recording what the service asked of it. */
final class InMemoryDurations implements RecordingDurationRepositoryInterface
{
    /** @var list<RecordingToMeasure> */
    public array $due = [];

    /** @var list<int> */
    public array $refuseClaims = [];

    /** @var array<int, float> */
    public array $recorded = [];

    /** @var array<int, array{failure: string, retryAt: DateTimeImmutable, counts: bool}> */
    public array $failures = [];

    /** @var list<int> */
    public array $abandoned = [];

    public int $lastLimit = 0;

    public function due(int $limit, int $maxAttempts, DateTimeImmutable $now): array
    {
        $this->lastLimit = $limit;

        return count($this->due) > $limit ? array_slice($this->due, 0, $limit) : $this->due;
    }

    public function claim(int $recordingId, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool
    {
        return !in_array($recordingId, $this->refuseClaims, true);
    }

    public function record(int $recordingId, float $seconds, DateTimeImmutable $now): bool
    {
        if (isset($this->recorded[$recordingId])) {
            return false;
        }

        $this->recorded[$recordingId] = $seconds;

        return true;
    }

    public function failed(int $recordingId, string $failure, DateTimeImmutable $retryAt, bool $countsAsAttempt): void
    {
        $this->failures[$recordingId] = ['failure' => $failure, 'retryAt' => $retryAt, 'counts' => $countsAsAttempt];
    }

    public function abandon(int $recordingId, string $failure, int $maxAttempts): void
    {
        $this->abandoned[] = $recordingId;
    }

    public function unmeasured(int $maxAttempts): RecordingDurationBacklog
    {
        return new RecordingDurationBacklog(count($this->recorded), count($this->due), count($this->abandoned));
    }

    public function resetExhausted(int $maxAttempts): int
    {
        return 0;
    }
}
