<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\Items\ItemSyncState;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use DateTimeImmutable;

use function count;

/**
 * Records what was enqueued, without pretending to be a queue.
 *
 * `OrderSyncServiceTest` and `ImportRecordingsCommandTest` care about one question — which order ids
 * reached {@see enqueue()}, and in which list — so this keeps exactly that and nothing else. A double
 * that modelled statuses as well would invite assertions about behaviour those tests do not own.
 */
final class RecordingItemSyncState implements ItemSyncStateRepositoryInterface
{
    /** @var list<array{accountId: int, changed: list<int>, unchanged: list<int>}> */
    public array $enqueued = [];

    public function enqueue(
        int $accountId,
        array $changedIds,
        array $unchangedIds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        $this->enqueued[] = ['accountId' => $accountId, 'changed' => $changedIds, 'unchanged' => $unchangedIds];

        return count($changedIds) + count($unchangedIds);
    }

    /** Nothing is enqueued here, so every order reads as it was seeded. */
    public function statusesFor(int $accountId, array $sourceOrderIds): array
    {
        $statuses = [];

        foreach ($sourceOrderIds as $sourceOrderId) {
            $statuses[$sourceOrderId] = ['status' => $this->status, 'error' => null];
        }

        return $statuses;
    }

    public function nextQueued(int $limit, DateTimeImmutable $now): array
    {
        return [];
    }

    public function claim(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        array $fromStatuses,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?ItemSyncState {
        return null;
    }

    public function markSynced(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        int $itemCount,
        string $responseHash,
        string $responseJson,
        DateTimeImmutable $now,
    ): void {}

    public function markFailed(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        string $error,
        ?DateTimeImmutable $retryAfter,
        DateTimeImmutable $now,
    ): void {}

    public function expireLeases(DateTimeImmutable $now): int
    {
        return 0;
    }
}
