<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Order58\Infrastructure\DbCaseOrderSyncRunRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The one-a-day allowance, against real MySQL. **No test here calls the Order58 API.**
 *
 * ## Why this has to be an integration test
 *
 * The rate limit *is* a unique index. `claim()` inserts and lets the database decide; a double that
 * re-implemented "has it run today?" in PHP would be testing the double, and the thing worth knowing
 * is precisely whether MySQL refuses the second insert. That refusal is the only thing standing
 * between a second browser tab and a second request to a provider who permits one a day.
 *
 * The days are in 2099 so they cannot collide with a real run, and every row is removed again.
 */
final class CaseOrderSyncRunTest extends Unit
{
    private const DAY = '2099-03-04';
    private const OTHER_DAY = '2099-03-05';
    private const ZONE = 'America/New_York';

    private ConnectionInterface $connection;
    private DbCaseOrderSyncRunRepository $runs;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $this->ready = true;
        $this->runs = new DbCaseOrderSyncRunRepository($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------------------- the allowance

    /**
     * The second claim of a day is refused by the database.
     *
     * This is the whole rate limit. There is no read before the insert, so there is no window between
     * deciding and acting — which is what makes two simultaneous presses safe rather than merely
     * unlikely.
     */
    public function testOnlyOneRunCanBeActiveAtATime(): void
    {
        $first = $this->claim(self::DAY, '09:00:00');

        $this->assertNotNull($first);
        $this->assertSame(self::DAY, $first->businessDay);
        $this->assertSame(CaseOrderSyncStatus::Claimed, $first->status);
        $this->assertFalse(
            $first->status->hasSpentTheAllowance(),
            'claiming is not asking: nothing has been sent yet',
        );

        $this->assertNull(
            $this->claim(self::DAY, '09:00:01'),
            'the active-run index must refuse a second claim while one is unfinished',
        );
    }

    /** A FINISHED run frees the claim: several runs a day is the point of the new design. */
    public function testAFinishedRunFreesTheClaimForAnotherRunOnTheSameDay(): void
    {
        $run = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($run);

        $this->runs->complete(
            $run->id,
            ['inserted' => 5, 'updated' => 2, 'unchanged' => 0, 'skipped' => 1, 'items' => 40, 'failures' => 0],
            null,
            $this->at('09:00:30'),
        );

        $second = $this->claim(self::DAY, '09:01:00');

        $this->assertNotNull($second, 'a finished run frees the claim for another on the same day');
        $this->assertNotSame($run->id, $second->id);
    }

    /** A failed run does not free it either — the request may well have been made. */
    public function testAFailedRunStillHoldsItsDay(): void
    {
        $run = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($run);

        $this->runs->fail($run->id, 'fetch', 'The provider refused with HTTP 401.', $this->at('09:00:05'));

        $this->assertNotNull($this->claim(self::DAY, '09:02:00'));
    }

    /** A different day is still blocked while a run is active: the lock is global, not per day. */
    public function testAnotherDayIsAlsoBlockedWhileARunIsActive(): void
    {
        $this->assertNotNull($this->claim(self::DAY, '09:00:00'));
        $this->assertNull($this->claim(self::OTHER_DAY, '09:00:00'));
    }

    /** An expired lease is recovered as ABANDONED, which still counts the request as possibly sent. */
    public function testAnExpiredLeaseIsRecoveredWithoutClaimingNothingWasSent(): void
    {
        $run = $this->runs->claim(self::DAY, self::ZONE, 'manual', $this->at('09:00:30'), $this->at('09:00:00'));
        $this->assertNotNull($run);

        $this->assertSame(1, $this->runs->recoverExpired($this->at('09:30:00')));

        $closed = $this->runs->find($run->id);
        $this->assertSame(CaseOrderSyncStatus::Failed, $closed?->status);
        $this->assertSame(CaseOrderSyncRun::STAGE_ABANDONED, $closed?->stage);
        $this->assertTrue($closed->allowanceSpent());

        // And the claim is free again.
        $this->assertNotNull($this->claim(self::DAY, '09:31:00'));
    }

    // ------------------------------------------------------------------ the staged response

    /**
     * The response is kept on a failure and reported as present, without being selected.
     *
     * Both halves matter. Keeping it is what lets a failed import be retried locally instead of
     * costing a day; not selecting it is what lets the status endpoint poll this table every two
     * seconds, because the column holds up to sixteen megabytes.
     */
    public function testAFailedRunKeepsItsResponseAndSaysSoWithoutReturningIt(): void
    {
        $run = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($run);

        $this->runs->markFetched($run->id, 200, 1_234_567, '[{"id":1}]', $this->at('09:00:10'));
        $this->runs->fail($run->id, 'import', 'Could not be imported.', $this->at('09:00:20'));

        $failed = $this->runs->forDay(self::DAY);
        $this->assertNotNull($failed);
        $this->assertSame(CaseOrderSyncStatus::Failed, $failed->status);
        $this->assertSame('import', $failed->stage);
        $this->assertSame(200, $failed->httpStatus);
        $this->assertSame(1_234_567, $failed->responseBytes);

        $this->assertTrue($failed->hasStagedPayload);
        $this->assertTrue(
            $failed->canRetryWithoutCallingTheProvider(),
            'the day is spent, but the work left is local',
        );

        // Fetched on purpose, by the one caller that imports.
        $this->assertSame('[{"id":1}]', $this->runs->stagedPayload($run->id));
    }

    public function testDiscardingTheResponseLeavesTheRunIntact(): void
    {
        $run = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($run);

        $this->runs->markFetched($run->id, 200, 64, '[{"id":1}]', $this->at('09:00:10'));
        $this->runs->markProcessing($run->id, 1, $this->at('09:00:11'));
        $this->runs->complete(
            $run->id,
            ['inserted' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'items' => 3, 'failures' => 0],
            null,
            $this->at('09:00:12'),
        );
        $this->runs->discardStagedPayload($run->id, $this->at('09:00:13'));

        $done = $this->runs->forDay(self::DAY);
        $this->assertNotNull($done);
        $this->assertSame(CaseOrderSyncStatus::Completed, $done->status);
        $this->assertSame(1, $done->ordersReceived);
        $this->assertSame(1, $done->ordersInserted);
        $this->assertSame(3, $done->itemsImported);
        $this->assertSame(1, $done->ordersWritten());

        $this->assertFalse($done->hasStagedPayload);
        $this->assertNull($this->runs->stagedPayload($run->id));
        $this->assertFalse($done->canRetryWithoutCallingTheProvider(), 'nothing left to retry');
    }

    /**
     * A completed run keeps its summary, because "199 of 200" is a success with something to read.
     *
     * The alternative — clearing it on success — would make a partial import indistinguishable from a
     * clean one, and the one order that did not make it would be invisible.
     */
    public function testACompletedRunKeepsItsSummary(): void
    {
        $run = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($run);

        $this->runs->complete(
            $run->id,
            ['inserted' => 199, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1, 'items' => 900, 'failures' => 0],
            'Order 17066031 names no store (account_id).',
            $this->at('09:00:30'),
        );

        $done = $this->runs->forDay(self::DAY);
        $this->assertNotNull($done);
        $this->assertSame(1, $done->ordersSkipped);
        $this->assertStringContainsString('17066031', (string) $done->errorSummary);
    }

    // ------------------------------------------------------------------------------- reading back

    public function testLatestReturnsTheMostRecentRun(): void
    {
        $first = $this->claim(self::DAY, '09:00:00');
        $this->assertNotNull($first);

        // Settled, because only one run may be active at a time.
        $this->runs->fail($first->id, 'fetch', 'refused', $this->at('09:00:10'));

        $this->claim(self::OTHER_DAY, '09:00:20');

        $latest = $this->runs->latest();

        $this->assertNotNull($latest);
        $this->assertSame(self::OTHER_DAY, $latest->businessDay);
    }

    /** The zone is stored beside the day, so a later settings change stays auditable. */
    public function testTheClaimRecordsTheZoneTheDayWasComputedIn(): void
    {
        $this->runs->claim(self::DAY, 'Asia/Kolkata', 'manual', $this->at('10:00:00'), $this->at('09:00:00'));

        $run = $this->runs->forDay(self::DAY);
        $this->assertNotNull($run);
        $this->assertSame('Asia/Kolkata', $run->timezone);
    }

    public function testAnUnclaimedDayReadsAsNothing(): void
    {
        $this->assertNull($this->runs->forDay(self::DAY));
        $this->assertNull($this->runs->find(0));
    }

    // ------------------------------------------------------------------------------------ fixtures

    /** A claim with a generous lease, which is what every case here wants unless it says otherwise. */
    private function claim(string $day, string $time): ?CaseOrderSyncRun
    {
        return $this->runs->claim($day, self::ZONE, 'manual', $this->at('23:59:59'), $this->at($time));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2099-03-04 ' . $time, new DateTimeZone('UTC'));
    }

    /** The two fixture days only. Nothing else in this table is read, written or removed. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_case_order_sync_runs}}', [
                'business_day' => [self::DAY, self::OTHER_DAY],
            ])
            ->execute();
    }
}
