<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Items\BatchOutcome;
use App\Order58\Domain\Items\EnrolmentOutcome;
use App\Order58\Domain\Items\ItemSyncBatchProgress;
use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function count;
use function is_array;
use function max;
use function strtotime;

use const PHP_INT_MAX;
use const SORT_DESC;

/**
 * Per-operation batch progress, in MySQL.
 *
 * ## Nothing here keeps a running total
 *
 * Every figure {@see progressFor()} returns is aggregated from the enrolment rows when asked. The
 * `outcome` column is a cache of that aggregate, refreshed after settlement so a finished batch stops
 * being recomputed — and corrected on read whenever it disagrees with the rows. A process killed
 * between a settlement and an increment therefore leaves nothing wrong behind, because there is no
 * increment to miss.
 */
final readonly class DbItemSyncBatchRepository implements ItemSyncBatchRepositoryInterface
{
    private const BATCHES = '{{%order58_item_sync_batches}}';
    private const ENROLMENTS = '{{%order58_item_sync_batch_orders}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function open(
        int $storeAccountId,
        string $triggerSource,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $requestedByAdminId,
        DateTimeImmutable $now,
    ): int {
        $stamp = DbDateTime::format($now);

        $this->connection->createCommand()->insert(self::BATCHES, [
            'store_account_id' => $storeAccountId,
            'trigger_source' => $triggerSource,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'requested_by_admin_id' => $requestedByAdminId,
            'started_at' => $stamp,
            'outcome' => BatchOutcome::Running->value,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    public function enrol(
        int $batchId,
        int $storeAccountId,
        array $orders,
        array $errors,
        int $eligibleCount,
        bool $ordersTruncated,
        DateTimeImmutable $now,
    ): void {
        $stamp = DbDateTime::format($now);
        $rows = [];

        foreach ($orders as $sourceOrderId => $settledAs) {
            $rows[] = [
                $batchId,
                $storeAccountId,
                $sourceOrderId,
                // The status this batch found. Kept so "already synced when this began" stays true
                // after a later refresh changes the order itself.
                $settledAs === null ? 'PENDING' : $settledAs->value,
                // Only an order with a request still to come can hold a batch open.
                $settledAs === null ? 1 : 0,
                $settledAs === null ? null : $stamp,
                $settledAs?->value,
                $settledAs === EnrolmentOutcome::Failed ? ($errors[$sourceOrderId] ?? null) : null,
                $stamp,
                $stamp,
            ];
        }

        if ($rows !== []) {
            // Idempotent: the same order enrolled twice in one batch is one row. Settlement and
            // outcome are never overwritten here — a second sync must not un-settle work the first
            // one already finished.
            $this->connection->createCommand()->insertBatch(
                self::ENROLMENTS,
                $rows,
                [
                    'batch_id', 'store_account_id', 'source_order_id', 'enrolled_state', 'required',
                    'settled_at', 'outcome', 'error', 'created_at', 'updated_at',
                ],
            )->execute();
        }

        $this->connection->createCommand()->update(
            self::BATCHES,
            [
                // The scope the sync INTENDED. Larger than the rows when it was cut short, which is
                // what `orders_truncated` then explains.
                'eligible_count' => max($eligibleCount, count($rows)),
                'orders_truncated' => $ordersTruncated ? 1 : 0,
                'updated_at' => $stamp,
            ],
            ['id' => $batchId],
        )->execute();

        $this->refreshOutcome($batchId, $now);
    }

    public function recordOrdersResult(
        int $batchId,
        int $ordersReceived,
        int $ordersSaved,
        bool $truncated,
        ?string $error,
        DateTimeImmutable $now,
    ): void {
        $this->connection->createCommand()->update(
            self::BATCHES,
            [
                'orders_received' => $ordersReceived,
                'orders_saved' => $ordersSaved,
                'orders_truncated' => $truncated ? 1 : 0,
                'orders_error' => $error,
                'updated_at' => DbDateTime::format($now),
            ],
            ['id' => $batchId],
        )->execute();
    }

    public function settle(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        ?string $error,
        DateTimeImmutable $now,
    ): int {
        $stamp = DbDateTime::format($now);

        // Only rows still waiting. A batch that already settled this order keeps the result it saw —
        // re-settling would rewrite history every time the order is refreshed for somebody else.
        $settled = $this->connection->createCommand()->update(
            self::ENROLMENTS,
            ['settled_at' => $stamp, 'outcome' => $outcome->value, 'error' => $error, 'updated_at' => $stamp],
            [
                'store_account_id' => $storeAccountId,
                'source_order_id' => $sourceOrderId,
                'settled_at' => null,
            ],
        )->execute();

        $this->refreshOutcomesFor($storeAccountId, $sourceOrderId, $now);

        return $settled;
    }

    public function reconcileAfterSuccess(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        DateTimeImmutable $now,
    ): int {
        $stamp = DbDateTime::format($now);

        // Only the rows that FAILED, and `settled_at` is deliberately untouched: when this batch
        // stopped waiting is history, while what it concluded about the order is a verdict that a
        // later success makes wrong.
        $corrected = $this->connection->createCommand()->update(
            self::ENROLMENTS,
            ['outcome' => $outcome->value, 'error' => null, 'updated_at' => $stamp],
            [
                'store_account_id' => $storeAccountId,
                'source_order_id' => $sourceOrderId,
                'outcome' => EnrolmentOutcome::Failed->value,
            ],
        )->execute();

        if ($corrected > 0) {
            $this->refreshOutcomesFor($storeAccountId, $sourceOrderId, $now);
        }

        return $corrected;
    }

    public function progressFor(
        int $batchId,
        DateTimeImmutable $now,
        int $stalledAfterSeconds,
    ): ?ItemSyncBatchProgress {
        $batch = (new Query($this->connection))
            ->select([
                'id', 'trigger_source', 'orders_received', 'orders_saved', 'eligible_count',
                'orders_truncated', 'orders_error', 'started_at', 'finished_at', 'outcome',
                'updated_at',
            ])
            ->from(self::BATCHES)
            ->where(['id' => $batchId])
            ->one();

        if (!is_array($batch)) {
            return null;
        }

        // One grouped read for every counter the page shows.
        $counts = (new Query($this->connection))
            ->select([
                'enrolled' => 'COUNT(*)',
                'pending' => 'SUM(CASE WHEN settled_at IS NULL THEN 1 ELSE 0 END)',
                'failed' => 'SUM(CASE WHEN outcome = \'FAILED\' THEN 1 ELSE 0 END)',
                'skipped' => 'SUM(CASE WHEN outcome = \'SKIPPED\' THEN 1 ELSE 0 END)',
                'last_settled' => 'MAX(settled_at)',
            ])
            ->from(self::ENROLMENTS)
            ->where(['batch_id' => $batchId])
            ->one();

        $enrolled = is_array($counts) ? (int) $counts['enrolled'] : 0;
        $pending = is_array($counts) ? (int) $counts['pending'] : 0;
        $failed = is_array($counts) ? (int) $counts['failed'] : 0;
        $skipped = is_array($counts) ? (int) $counts['skipped'] : 0;

        $truncated = (int) $batch['orders_truncated'] === 1;
        $ordersError = $batch['orders_error'] === null ? null : (string) $batch['orders_error'];

        $outcome = BatchOutcome::decide(
            $pending,
            $failed,
            $truncated,
            // An Orders Sync that failed outright never enrolled anything, so "no rows" alone cannot
            // tell the difference between that and a scope with nothing to do.
            $ordersError !== null && $enrolled === 0,
        );

        // Stalled is about silence, not failure: the commonest cause is that the importer unit is not
        // installed or not running, which is a deployment fact rather than a synchronisation one.
        $lastMovement = is_array($counts) && $counts['last_settled'] !== null
            ? (string) $counts['last_settled']
            : (string) $batch['updated_at'];

        $stalled = $outcome === BatchOutcome::Running
            && $pending > 0
            && (strtotime($lastMovement) !== false)
            && ($now->getTimestamp() - (int) strtotime($lastMovement)) > $stalledAfterSeconds;

        return new ItemSyncBatchProgress(
            batchId: (int) $batch['id'],
            triggerSource: (string) $batch['trigger_source'],
            outcome: $outcome,
            ordersReceived: (int) $batch['orders_received'],
            ordersSaved: (int) $batch['orders_saved'],
            enrolled: $enrolled,
            pending: $pending,
            done: $enrolled - $pending - $failed,
            failed: $failed,
            skipped: $skipped,
            ordersTruncated: $truncated,
            ordersError: $ordersError,
            startedAt: $batch['started_at'] === null ? null : (string) $batch['started_at'],
            finishedAt: $batch['finished_at'] === null ? null : (string) $batch['finished_at'],
            stalled: $stalled,
        );
    }

    public function refreshOutcome(int $batchId, DateTimeImmutable $now): BatchOutcome
    {
        // `0` for the stall window: a verdict must never depend on how long something has been quiet.
        $progress = $this->progressFor($batchId, $now, PHP_INT_MAX);

        if ($progress === null) {
            return BatchOutcome::Running;
        }

        $stamp = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::BATCHES,
            [
                'outcome' => $progress->outcome->value,
                // Stamped once, when it first finishes. A later reconciliation changes the verdict,
                // never the moment the operation stopped waiting.
                'finished_at' => new Expression(
                    $progress->outcome->isFinished() ? 'COALESCE(finished_at, :finished)' : 'NULL',
                    $progress->outcome->isFinished() ? ['finished' => $stamp] : [],
                ),
                'updated_at' => $stamp,
            ],
            ['id' => $batchId],
        )->execute();

        return $progress->outcome;
    }

    public function latestFor(int $storeAccountId): ?int
    {
        $id = (new Query($this->connection))
            ->select('id')
            ->from(self::BATCHES)
            ->where(['store_account_id' => $storeAccountId])
            ->orderBy(['started_at' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();

        return $id === null || $id === false ? null : (int) $id;
    }

    /** Re-derive the verdict for every batch that was waiting on this order. */
    private function refreshOutcomesFor(int $storeAccountId, int $sourceOrderId, DateTimeImmutable $now): void
    {
        $batchIds = (new Query($this->connection))
            ->select('batch_id')
            ->distinct()
            ->from(self::ENROLMENTS)
            ->where(['store_account_id' => $storeAccountId, 'source_order_id' => $sourceOrderId])
            ->column();

        foreach ($batchIds as $batchId) {
            $this->refreshOutcome((int) $batchId, $now);
        }
    }
}
