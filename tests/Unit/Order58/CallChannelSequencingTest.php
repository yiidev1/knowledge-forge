<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Application\RecordingDownloader;
use App\Order58\Application\RecordingImportProcessor;
use App\Order58\Domain\CallImportHistoryPage;
use App\Order58\Domain\CallImportItem;
use App\Order58\Domain\CallImportMode;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Audio\AudioIngestionOutcome;
use App\Shared\Audio\AudioIngestionPortInterface;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Log\NullLogger;

use function array_key_first;
use function count;
use function intdiv;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * One worker run takes one call's channels, one after another, and stops in time.
 *
 * ## What changed and why it needed a test of its own
 *
 * A run used to take exactly one recording row, so a call a person selected — mixed, caller, callee —
 * needed three timer ticks to finish. That was two scheduler intervals of pure latency per click: the
 * provider is not slow, the schedule was. A run now drains the channels of the call it claimed.
 *
 * Two properties make that safe rather than merely faster, and both are asserted here because neither is
 * visible from the repository alone:
 *
 * 1. **It is bounded at one call.** Not a backlog drainer. The next call waits for the next tick.
 * 2. **It stops starting channels before the unit's `TimeoutStartSec=300` is in reach.** Checked before
 *    a sibling is claimed, so a channel this run declines is left `pending` rather than claimed and
 *    abandoned — the stale sweep gets nothing new to rescue.
 *
 * The provider is a Guzzle mock handler throughout; nothing here touches the network. Every channel is
 * answered 404, which settles each row without an ingestion step — enough to exercise the sequencing,
 * which is what this file is about.
 */
final class CallChannelSequencingTest extends Unit
{
    /** Three channels of one call, then three of another. */
    public function testOneRunTakesOneWholeCallAndLeavesTheNextAlone(): void
    {
        $repository = new RecordingChannelQueue(pending: 6);
        $processor = $this->processor($repository, new MutableClock());

        $handled = $processor->processNextCall();

        assertSame(3, $handled, 'The call it claimed, and all of it.');
        assertSame(['mixed', 'caller', 'callee'], $repository->claimedChannels);
        assertSame(3, $repository->pendingCount(), 'The second call is untouched, waiting for the next tick.');
    }

    /** And they are taken strictly one at a time: each claim follows the previous outcome being written. */
    public function testEachChannelIsSettledBeforeTheNextIsClaimed(): void
    {
        $repository = new RecordingChannelQueue(pending: 3);
        $processor = $this->processor($repository, new MutableClock());

        $processor->processNextCall();

        // Interleaved, never batched: claim, settle, claim, settle, claim, settle. A parallel
        // implementation would show three claims before the first write.
        assertSame(
            ['claim', 'settle', 'claim', 'settle', 'claim', 'settle'],
            $repository->order,
            'A claim that ran ahead of a settle would mean two channels in flight at once.',
        );
    }

    /**
     * A run that has gone slow stops starting new channels.
     *
     * The clock is advanced 90 seconds per channel — a provider at its 60s read timeout plus ffprobe —
     * so the budget is reached partway through. What matters is not the exact count but that it stops
     * **and** that what it declined is still pending rather than stranded mid-claim.
     */
    public function testASlowRunStopsBeforeTheUnitTimeoutAndStrandsNothing(): void
    {
        $clock = new MutableClock();
        $repository = new RecordingChannelQueue(pending: 3, clock: $clock, secondsPerChannel: 90);
        $processor = $this->processor($repository, $clock);

        $handled = $processor->processNextCall();

        assertSame(3, $repository->pendingCount() + $handled, 'Every row is either done or still pending.');
        assertCount($handled, $repository->claimedChannels);
        assertSame(0, $repository->claimedButUnsettled, 'A declined channel must never be left claimed.');
        self::assertLessThan(3, $handled, 'At 90s a channel, the budget must cut the run short.');
        self::assertGreaterThanOrEqual(1, $handled, 'The channel it claimed is always attempted.');
    }

    /** A fast run is never cut short — the budget is for the bad day, not the normal one. */
    public function testAnOrdinaryRunIsNotCutShort(): void
    {
        $clock = new MutableClock();
        $repository = new RecordingChannelQueue(pending: 3, clock: $clock, secondsPerChannel: 3);
        $processor = $this->processor($repository, $clock);

        assertSame(3, $processor->processNextCall(), 'Three quick channels finish in one run.');
    }

    /** Nothing waiting is still nothing to do. */
    public function testAnEmptyQueueIsNoWork(): void
    {
        $processor = $this->processor(new RecordingChannelQueue(pending: 0), new MutableClock());

        assertSame(0, $processor->processNextCall());
    }

    private function processor(RecordingChannelQueue $repository, MutableClock $clock): RecordingImportProcessor
    {
        // Every channel answers 404, which settles each row without reaching ingestion. On caller and
        // callee that is NOT_AVAILABLE; on mixed it is a failure. Either way it is terminal, which is
        // all the sequencing needs.
        $handler = new MockHandler([
            new GuzzleResponse(404, [], 'not found'),
            new GuzzleResponse(404, [], 'not found'),
            new GuzzleResponse(404, [], 'not found'),
            new GuzzleResponse(404, [], 'not found'),
            new GuzzleResponse(404, [], 'not found'),
            new GuzzleResponse(404, [], 'not found'),
        ]);

        return new RecordingImportProcessor(
            $repository,
            new RecordingDownloader(
                new ChannelApiProbe(httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)])),
            ),
            new NeverCalledIngestion(),
            new SecretRedactor(),
            $clock,
            new NullLogger(),
            3,
        );
    }
}

/**
 * The pending channel rows, as calls of three, with every hand-off recorded.
 *
 * `order` is what proves sequencing: a claim appearing before the previous channel's settle would mean
 * two downloads in flight, which is the one thing this design must never do.
 */
final class RecordingChannelQueue implements CallImportRepositoryInterface
{
    /** @var list<string> */
    public array $claimedChannels = [];

    /** @var list<string> 'claim' and 'settle', interleaved if the work is sequential */
    public array $order = [];

    public int $claimedButUnsettled = 0;

    /** @var array<int, array{session: string, channel: RecordingChannel}> */
    private array $rows = [];

    public function __construct(
        int $pending,
        private readonly ?MutableClock $clock = null,
        private readonly int $secondsPerChannel = 0,
    ) {
        $channels = RecordingChannel::all();

        for ($i = 0; $i < $pending; $i++) {
            $this->rows[$i] = [
                'session' => (string) (22487129 + intdiv($i, 3)),
                'channel' => $channels[$i % 3],
            ];
        }
    }

    public function pendingCount(): int
    {
        return count($this->rows);
    }

    public function claimNext(DateTimeImmutable $now): ?CallImportItem
    {
        return $this->take(array_key_first($this->rows), $now);
    }

    public function claimNextForCall(
        int $storeSourceId,
        string $callSessionId,
        DateTimeImmutable $now,
    ): ?CallImportItem {
        foreach ($this->rows as $index => $row) {
            if ($row['session'] === $callSessionId) {
                return $this->take($index, $now);
            }
        }

        return null;
    }

    private function take(?int $index, DateTimeImmutable $now): ?CallImportItem
    {
        if ($index === null || !isset($this->rows[$index])) {
            return null;
        }

        $row = $this->rows[$index];
        unset($this->rows[$index]);

        $this->claimedChannels[] = $row['channel']->value;
        $this->order[] = 'claim';
        ++$this->claimedButUnsettled;

        return new CallImportItem(
            id: $index + 1,
            batchId: 1,
            storeSourceId: 1491,
            callSessionId: $row['session'],
            channel: $row['channel'],
            callTimeRaw: '2026-09-25 06:09:16',
            callDate: '2026-09-25',
            orderId: '16547451',
            status: Order58ImportStatus::Fetching,
            attempts: 0,
            errorCode: null,
            errorMessage: null,
            bytes: null,
            durationSeconds: null,
            conversationPublicId: null,
            completedAt: null,
            updatedAt: $now,
            recordingCompany: 'WGEU',
            provider: 'WHISPER',
            generateAiAudio: false,
            requestedByAdminId: 1,
            mode: CallImportMode::DownloadOnly,
        );
    }

    /** Every terminal write passes through here, which is where a channel's elapsed time is modelled. */
    private function settled(): void
    {
        $this->order[] = 'settle';
        --$this->claimedButUnsettled;

        if ($this->clock !== null && $this->secondsPerChannel > 0) {
            $this->clock->advance('+' . $this->secondsPerChannel . ' seconds');
        }
    }

    public function markImported(
        int $id,
        string $conversationPublicId,
        int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $this->settled();
    }

    public function markSettled(
        int $id,
        Order58ImportStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $this->settled();
    }

    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void {
        $this->settled();
    }

    // ---------------------------------------------------------------- not reached by the worker path

    public function createBatch(
        int $storeSourceId,
        string $triggeredBy,
        ?int $requestedByAdminId,
        string $provider,
        bool $generateAiAudio,
        string $recordingCompany,
        DateTimeImmutable $now,
        CallImportMode $mode = CallImportMode::DownloadAndTranscribe,
    ): int {
        return 1;
    }

    public function queueCall(
        int $batchId,
        int $storeSourceId,
        string $callSessionId,
        string $callTimeRaw,
        string $callDate,
        ?string $orderId,
        array $channels,
        DateTimeImmutable $now,
    ): int {
        return 0;
    }

    public function statusesFor(int $storeSourceId, array $callSessionIds): array
    {
        return [];
    }

    public function acquisitionsFor(int $storeSourceId, array $callSessionIds): array
    {
        return [];
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        return 0;
    }

    public function retry(int $id, DateTimeImmutable $now): bool
    {
        return false;
    }

    public function history(?int $storeSourceId, int $limit): array
    {
        return [];
    }

    public function historyPage(int $page, int $perPage, ?CallImportMode $mode = null): CallImportHistoryPage
    {
        return new CallImportHistoryPage([], 0, $page, $perPage);
    }

    public function findItem(int $id): ?CallImportItem
    {
        return null;
    }
}

/** Ingestion is never reached: every channel is answered 404, which settles before the file step. */
final class NeverCalledIngestion implements AudioIngestionPortInterface
{
    public function ingestFile(
        int $storeSourceId,
        string $path,
        string $filename,
        string $recordingType,
        ?string $orderId,
        string $provider,
        bool $generateAiAudio,
        int $adminUserId,
        ?string $callSessionId = null,
        ?string $callTimeRaw = null,
        bool $transcribe = true,
    ): AudioIngestionOutcome {
        throw new \LogicException('No channel in this test should reach ingestion.');
    }
}
