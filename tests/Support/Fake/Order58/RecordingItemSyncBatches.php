<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\Items\BatchOutcome;
use App\Order58\Domain\Items\EnrolmentOutcome;
use App\Order58\Domain\Items\ItemSyncBatchProgress;
use App\Order58\Domain\Items\ItemSyncBatchRepositoryInterface;
use DateTimeImmutable;
use RuntimeException;

use function array_map;

/**
 * Records what a sync told the batch bookkeeping, without a database.
 *
 * The settlement calls are the interesting part: they are how a test proves that ONE provider request
 * settles every batch waiting on that order, and that a later success reconciles an earlier failure.
 */
final class RecordingItemSyncBatches implements ItemSyncBatchRepositoryInterface
{
    public int $nextId = 1;

    /** @var list<array{store: int, trigger: string, from: ?string, to: ?string}> */
    public array $opened = [];

    /** @var list<array{batch: int, orders: array<int, EnrolmentOutcome|null>, eligible: int, truncated: bool}> */
    public array $enrolled = [];

    /** @var list<array{batch: int, received: int, saved: int, truncated: bool, error: ?string}> */
    public array $ordersResults = [];

    /** @var list<array{order: int, outcome: EnrolmentOutcome, error: ?string}> */
    public array $settled = [];

    /** @var list<array{order: int, outcome: EnrolmentOutcome}> */
    public array $reconciled = [];

    /** Set to make every write throw, proving a sync survives broken bookkeeping. */
    public bool $failEverything = false;

    public function open(
        int $storeAccountId,
        string $triggerSource,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $requestedByAdminId,
        DateTimeImmutable $now,
    ): int {
        $this->guard();
        $this->opened[] = ['store' => $storeAccountId, 'trigger' => $triggerSource, 'from' => $dateFrom, 'to' => $dateTo];

        return $this->nextId++;
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
        $this->guard();
        $this->enrolled[] = [
            'batch' => $batchId,
            'orders' => $orders,
            'eligible' => $eligibleCount,
            'truncated' => $ordersTruncated,
        ];
    }

    public function recordOrdersResult(
        int $batchId,
        int $ordersReceived,
        int $ordersSaved,
        bool $truncated,
        ?string $error,
        DateTimeImmutable $now,
    ): void {
        $this->guard();
        $this->ordersResults[] = [
            'batch' => $batchId,
            'received' => $ordersReceived,
            'saved' => $ordersSaved,
            'truncated' => $truncated,
            'error' => $error,
        ];
    }

    public function settle(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        ?string $error,
        DateTimeImmutable $now,
    ): int {
        $this->guard();
        $this->settled[] = ['order' => $sourceOrderId, 'outcome' => $outcome, 'error' => $error];

        return 1;
    }

    public function reconcileAfterSuccess(
        int $storeAccountId,
        int $sourceOrderId,
        EnrolmentOutcome $outcome,
        DateTimeImmutable $now,
    ): int {
        $this->guard();
        $this->reconciled[] = ['order' => $sourceOrderId, 'outcome' => $outcome];

        return 0;
    }

    public function progressFor(int $batchId, DateTimeImmutable $now, int $stalledAfterSeconds): ?ItemSyncBatchProgress
    {
        return null;
    }

    public function refreshOutcome(int $batchId, DateTimeImmutable $now): BatchOutcome
    {
        return BatchOutcome::Running;
    }

    public function latestFor(int $storeAccountId): ?int
    {
        return null;
    }

    /** @return list<EnrolmentOutcome> the outcome each settlement recorded, in order */
    public function settledOutcomes(): array
    {
        return array_map(static fn(array $row): EnrolmentOutcome => $row['outcome'], $this->settled);
    }

    private function guard(): void
    {
        if ($this->failEverything) {
            throw new RuntimeException('batch bookkeeping is unavailable');
        }
    }
}
