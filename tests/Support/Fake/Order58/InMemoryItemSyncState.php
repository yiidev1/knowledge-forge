<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\Items\ItemSyncState;
use App\Order58\Domain\Items\ItemSyncStateRepositoryInterface;
use DateTimeImmutable;

use function in_array;

/**
 * The per-order item-sync state, in memory.
 *
 * Models the three things the real repository's SQL enforces and a naive double would not: the claim
 * precondition, the live-lease check, and the fact that a terminal write only lands when the token
 * still matches. Without those a test would "pass" against behaviour MySQL would reject.
 */
final class InMemoryItemSyncState implements ItemSyncStateRepositoryInterface
{
    public string $status = 'QUEUED';
    public ?string $owner = null;
    public ?DateTimeImmutable $leasedUntil = null;
    public ?string $responseHash = null;
    public int $attempts = 0;
    /** How many times a claim was ATTEMPTED, won or not — so "never even tried" is observable. */
    public int $claims = 0;
    public int $itemCount = 0;
    public ?DateTimeImmutable $syncedAt = null;
    public ?string $responseJson = null;
    public ?string $error = null;
    public ?DateTimeImmutable $retryAfter = null;

    /** @var list<string> every terminal write that was accepted */
    public array $writes = [];

    public function claim(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        array $fromStatuses,
        DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?ItemSyncState {
        ++$this->claims;

        if (!in_array($this->status, $fromStatuses, true)) {
            return null;
        }

        if ($this->leasedUntil !== null && $this->leasedUntil > $now) {
            return null;   // somebody holds a live lease
        }

        $this->status = 'PROCESSING';
        $this->owner = $leaseOwner;
        $this->leasedUntil = $now->modify('+' . $leaseSeconds . ' seconds');
        ++$this->attempts;

        return new ItemSyncState(
            accountId: $accountId,
            sourceOrderId: $sourceOrderId,
            leaseOwner: $leaseOwner,
            responseHash: $this->responseHash,
            attempts: $this->attempts,
            hasSnapshot: $this->syncedAt !== null,
        );
    }

    public function markSynced(
        int $accountId,
        int $sourceOrderId,
        ?string $leaseOwner,
        int $itemCount,
        string $responseHash,
        ?string $responseJson,
        DateTimeImmutable $now,
    ): void {
        if ($this->owner !== $leaseOwner) {
            return;   // the real repository's WHERE clause would match nothing
        }

        $this->status = $itemCount === 0 ? 'EMPTY' : 'SYNCED';
        $this->itemCount = $itemCount;
        $this->responseHash = $responseHash;
        $this->responseJson = $responseJson;
        $this->syncedAt = $now;
        $this->attempts = 0;
        $this->error = null;
        $this->retryAfter = null;
        $this->owner = null;
        $this->leasedUntil = null;
        $this->writes[] = 'synced';
    }

    public function markFailed(
        int $accountId,
        int $sourceOrderId,
        string $leaseOwner,
        string $error,
        ?DateTimeImmutable $retryAfter,
        DateTimeImmutable $now,
    ): void {
        if ($this->owner !== $leaseOwner) {
            return;
        }

        // Everything describing the previous snapshot is deliberately left alone.
        $this->status = 'FAILED';
        $this->error = $error;
        $this->retryAfter = $retryAfter;
        $this->owner = null;
        $this->leasedUntil = null;
        $this->writes[] = 'failed';
    }

    /**
     * Queue bookkeeping the service tests do not exercise.
     *
     * They drive one order directly through {@see claim()}, so modelling the three-statement enqueue
     * here would be inventing behaviour nothing asserts. `DbItemSyncStateRepository` has its own
     * integration coverage for it.
     */
    public function enqueue(
        int $accountId,
        array $changedIds,
        array $unchangedIds,
        int $maxAttempts,
        DateTimeImmutable $now,
    ): int {
        return 0;
    }

    /** The one order this double tracks, reported for whichever ids are asked about. */
    public function statusesFor(int $accountId, array $sourceOrderIds): array
    {
        $statuses = [];

        foreach ($sourceOrderIds as $sourceOrderId) {
            $statuses[$sourceOrderId] = ['status' => $this->status, 'error' => $this->error];
        }

        return $statuses;
    }

    public function nextQueued(int $limit, DateTimeImmutable $now): array
    {
        return $this->status === 'QUEUED' ? [['accountId' => 1731, 'sourceOrderId' => 16800401]] : [];
    }

    public function expireLeases(DateTimeImmutable $now): int
    {
        if ($this->status !== 'PROCESSING' || $this->leasedUntil === null || $this->leasedUntil > $now) {
            return 0;
        }

        $this->status = 'QUEUED';
        $this->owner = null;          // the token must stop matching
        $this->leasedUntil = null;

        return 1;
    }
}
