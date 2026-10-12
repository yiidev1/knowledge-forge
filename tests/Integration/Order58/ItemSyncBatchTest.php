<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\Items\BatchOutcome;
use App\Order58\Domain\Items\EnrolmentOutcome;
use App\Order58\Infrastructure\DbItemSyncBatchRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Per-operation batch progress, against real MySQL.
 *
 * ## What is really being guarded
 *
 * Two operations wanting the same order. A manual Orders Sync and the recording importer routinely
 * overlap on a date, and the design rests on one claim, one provider request, and both batches learning
 * the same answer. If settlement reached only one of them, the other would poll forever over work that
 * had already finished.
 *
 * And the rule that a batch is finished only when nothing is unsettled — including the awkward cases:
 * an order already current, an order past its retry cap, and a scope the Orders Sync cut short.
 */
final class ItemSyncBatchTest extends Unit
{
    private const STORE = 987656010;
    private const OTHER_STORE = 987656011;

    private ConnectionInterface $connection;
    private DbItemSyncBatchRepository $repo;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $exists = (int) $this->connection->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order58_item_sync_batches'",
        )->queryScalar();

        if ($exists === 0) {
            $this->markTestSkipped('order58_item_sync_batches does not exist — migration not applied.');
        }

        $this->ready = true;
        $this->repo = new DbItemSyncBatchRepository($this->connection);
        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    // ------------------------------------------------------------------ overlap

    /**
     * The case the junction table exists for.
     *
     * Two operations enrol the same order. One request is made for it, and BOTH learn the result —
     * which a single mutable column on the order could not express, because the second batch would
     * have overwritten the first.
     */
    public function testOneSettlementFinishesEveryBatchWaitingOnThatOrder(): void
    {
        $manual = $this->open('orders_sync');
        $recording = $this->open('recording_sync');

        $this->repo->enrol($manual, self::STORE, [1 => null], [], 1, false, $this->now());
        $this->repo->enrol($recording, self::STORE, [1 => null], [], 1, false, $this->now());

        $this->assertSame(BatchOutcome::Running, $this->progress($manual)->outcome);
        $this->assertSame(BatchOutcome::Running, $this->progress($recording)->outcome);

        // One provider request for order 1, reported once.
        $settled = $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Synced, null, $this->now());

        $this->assertSame(2, $settled, 'Both batches were waiting; both must be settled.');
        $this->assertSame(BatchOutcome::Completed, $this->progress($manual)->outcome);
        $this->assertSame(BatchOutcome::Completed, $this->progress($recording)->outcome);
    }

    /** Overlapping batches keep their own history: a later one does not re-open an earlier one. */
    public function testASecondBatchDoesNotDisturbOneAlreadySettled(): void
    {
        $first = $this->open('orders_sync');
        $this->repo->enrol($first, self::STORE, [1 => null], [], 1, false, $this->now());
        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Synced, null, $this->now());

        $second = $this->open('recording_sync');
        $this->repo->enrol($second, self::STORE, [1 => null], [], 1, false, $this->now());

        $this->assertSame(BatchOutcome::Completed, $this->progress($first)->outcome, 'Finished stays finished.');
        $this->assertSame(BatchOutcome::Running, $this->progress($second)->outcome);

        // And the next request settles only the one still waiting.
        $this->assertSame(1, $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Unchanged, null, $this->now()));
    }

    /** One store's operation never settles on another store's order of the same number. */
    public function testSettlementIsScopedToTheStore(): void
    {
        $mine = $this->open('orders_sync');
        $theirs = $this->open('orders_sync', self::OTHER_STORE);

        $this->repo->enrol($mine, self::STORE, [1 => null], [], 1, false, $this->now());
        $this->repo->enrol($theirs, self::OTHER_STORE, [1 => null], [], 1, false, $this->now());

        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Synced, null, $this->now());

        $this->assertSame(BatchOutcome::Completed, $this->progress($mine)->outcome);
        $this->assertSame(BatchOutcome::Running, $this->progress($theirs)->outcome);
    }

    // ------------------------------------------------------------------ enrolment honesty

    /** An order nobody will fetch cannot be allowed to hold a batch open. */
    public function testAnAlreadyCurrentOrderSettlesOnArrival(): void
    {
        $batch = $this->open('orders_sync');

        $this->repo->enrol($batch, self::STORE, [1 => EnrolmentOutcome::Skipped], [], 1, false, $this->now());

        $progress = $this->progress($batch);

        $this->assertSame(BatchOutcome::Completed, $progress->outcome, 'Nothing to wait for.');
        $this->assertSame(1, $progress->skipped);
        $this->assertSame(0, $progress->remaining());
    }

    /** An order past its attempt cap is a failure, not an eternal wait. */
    public function testAnOrderEnrolledAsFailedMakesTheBatchPartial(): void
    {
        $batch = $this->open('orders_sync');

        $this->repo->enrol(
            $batch,
            self::STORE,
            [1 => EnrolmentOutcome::Failed, 2 => EnrolmentOutcome::Skipped],
            [1 => 'The Order Items API refused.'],
            2,
            false,
            $this->now(),
        );

        $progress = $this->progress($batch);

        $this->assertSame(BatchOutcome::Partial, $progress->outcome);
        $this->assertSame(1, $progress->failed);
        $this->assertSame(0, $progress->remaining());
    }

    /** A mix settles only as each one finishes. */
    public function testABatchStaysRunningUntilEveryEnrolmentSettles(): void
    {
        $batch = $this->open('orders_sync');
        $this->repo->enrol($batch, self::STORE, [1 => null, 2 => null, 3 => null], [], 3, false, $this->now());

        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Synced, null, $this->now());
        $this->assertSame(BatchOutcome::Running, $this->progress($batch)->outcome);
        $this->assertSame(2, $this->progress($batch)->remaining());

        $this->repo->settle(self::STORE, 2, EnrolmentOutcome::Empty, null, $this->now());
        $this->assertSame(BatchOutcome::Running, $this->progress($batch)->outcome);

        $this->repo->settle(self::STORE, 3, EnrolmentOutcome::Unchanged, null, $this->now());
        $this->assertSame(BatchOutcome::Completed, $this->progress($batch)->outcome);
    }

    // ------------------------------------------------------------------ truncation

    /** An Orders Sync that ran out of time must never read as a completed operation. */
    public function testATruncatedScopeCanNeverReadAsCompleted(): void
    {
        $batch = $this->open('orders_sync');

        // It intended 50 orders and reached 1 before the deadline.
        $this->repo->enrol($batch, self::STORE, [1 => null], [], 50, true, $this->now());
        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Synced, null, $this->now());

        $progress = $this->progress($batch);

        $this->assertSame(BatchOutcome::Partial, $progress->outcome, 'An incomplete scope is not complete.');
        $this->assertTrue($progress->ordersTruncated);
        $this->assertSame(0, $progress->remaining());
    }

    /** An Orders Sync that failed outright is FAILED, not a quietly empty success. */
    public function testAnOrdersSyncFailureIsReportedAsFailed(): void
    {
        $batch = $this->open('orders_sync');

        $this->repo->recordOrdersResult($batch, 0, 0, false, 'The order synchronisation failed.', $this->now());

        $this->assertSame(BatchOutcome::Failed, $this->progress($batch)->outcome);
    }

    // ------------------------------------------------------------------ reconciliation

    /**
     * A later success corrects an earlier failure without rewriting when it happened.
     *
     * The batch said PARTIAL because one order failed. A retry succeeds, so that statement is no longer
     * true and the verdict is corrected — while `settled_at`, which records when this batch stopped
     * waiting, is left exactly as it was.
     */
    public function testASuccessfulRetryReconcilesAFailedBatch(): void
    {
        $batch = $this->open('orders_sync');
        $this->repo->enrol($batch, self::STORE, [1 => null], [], 1, false, $this->now());
        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Failed, 'provider refused', $this->now());

        $this->assertSame(BatchOutcome::Partial, $this->progress($batch)->outcome);
        $settledAt = $this->settledAtOf($batch, 1);

        $corrected = $this->repo->reconcileAfterSuccess(self::STORE, 1, EnrolmentOutcome::Synced, $this->now());

        $this->assertSame(1, $corrected);
        $this->assertSame(BatchOutcome::Completed, $this->progress($batch)->outcome);
        $this->assertSame($settledAt, $this->settledAtOf($batch, 1), 'When it stopped waiting is history.');
        $this->assertSame(0, $this->progress($batch)->failed);
    }

    /** Reconciliation touches only failures — a success never rewrites an unrelated verdict. */
    public function testReconciliationLeavesNonFailedEnrolmentsAlone(): void
    {
        $batch = $this->open('orders_sync');
        $this->repo->enrol($batch, self::STORE, [1 => EnrolmentOutcome::Skipped], [], 1, false, $this->now());

        $this->assertSame(0, $this->repo->reconcileAfterSuccess(self::STORE, 1, EnrolmentOutcome::Synced, $this->now()));
        $this->assertSame(1, $this->progress($batch)->skipped);
    }

    /** A batch already reported finished must not silently start running again. */
    public function testReconciliationNeverReopensAFinishedBatch(): void
    {
        $batch = $this->open('orders_sync');
        $this->repo->enrol($batch, self::STORE, [1 => null], [], 1, false, $this->now());
        $this->repo->settle(self::STORE, 1, EnrolmentOutcome::Failed, 'refused', $this->now());
        $this->repo->reconcileAfterSuccess(self::STORE, 1, EnrolmentOutcome::Synced, $this->now());

        $this->assertTrue($this->progress($batch)->outcome->isFinished());
        $this->assertSame(0, $this->progress($batch)->remaining());
    }

    // ------------------------------------------------------------------ display states

    /** Silence is reported as stalled — a statement about the importer, never a verdict. */
    public function testAQuietRunningBatchIsReportedAsStalled(): void
    {
        $batch = $this->open('orders_sync');
        $this->repo->enrol($batch, self::STORE, [1 => null], [], 1, false, $this->now());

        $muchLater = $this->now()->modify('+1 hour');
        $progress = $this->repo->progressFor($batch, $muchLater, 180);

        $this->assertNotNull($progress);
        $this->assertTrue($progress->stalled);
        $this->assertSame(BatchOutcome::Running, $progress->outcome, 'Stalled is not failed.');
        $this->assertStringContainsString('Waiting', $progress->state());
    }

    /** One outstanding order must not round up to a finished-looking 100%. */
    public function testProgressNeverReadsAsCompleteWhileWorkRemains(): void
    {
        $batch = $this->open('orders_sync');
        $orders = [];
        for ($i = 1; $i <= 200; ++$i) {
            $orders[$i] = null;
        }
        $this->repo->enrol($batch, self::STORE, $orders, [], 200, false, $this->now());

        for ($i = 1; $i <= 199; ++$i) {
            $this->repo->settle(self::STORE, $i, EnrolmentOutcome::Synced, null, $this->now());
        }

        $this->assertSame(99, $this->progress($batch)->percent());
        $this->assertSame(1, $this->progress($batch)->remaining());
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $this->assertNull($this->repo->progressFor(999999999, $this->now(), 180));
    }

    // ------------------------------------------------------------------ harness

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09 12:00:00');
    }

    private function open(string $trigger, int $store = self::STORE): int
    {
        return $this->repo->open($store, $trigger, '2026-10-01', '2026-10-02', null, $this->now());
    }

    private function progress(int $batchId): \App\Order58\Domain\Items\ItemSyncBatchProgress
    {
        $progress = $this->repo->progressFor($batchId, $this->now(), 180);

        $this->assertNotNull($progress);

        return $progress;
    }

    private function settledAtOf(int $batchId, int $sourceOrderId): ?string
    {
        $value = $this->connection->createCommand(
            'SELECT settled_at FROM {{%order58_item_sync_batch_orders}}
              WHERE batch_id = :b AND source_order_id = :o',
            ['b' => $batchId, 'o' => $sourceOrderId],
        )->queryScalar();

        return $value === null || $value === false ? null : (string) $value;
    }

    /** Scoped to this suite's own fixture stores, never a blanket delete. */
    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            // The junction rows go with their batch through the foreign key.
            $this->connection->createCommand()
                ->delete('{{%order58_item_sync_batches}}', ['store_account_id' => $store])->execute();
        }
    }
}
