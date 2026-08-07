<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Domain\SyncRunStatus;
use BadMethodCallException;
use DateTimeImmutable;

/**
 * In-memory {@see SyncRunRepositoryInterface} for the freshness-service unit test: only {@see latestByType()} and
 * {@see lastSuccessfulAtByType()} are exercised; every other method throws so an unexpected call is loud.
 */
final class InMemorySyncRunRepository implements SyncRunRepositoryInterface
{
    /** @var array<string, SyncRun> */
    private array $latest = [];

    /** @var array<string, DateTimeImmutable> */
    private array $lastSuccess = [];

    /** Controls {@see enqueue()} for the scheduler test: the next id to hand out, or coalesce/throw. */
    public int $nextEnqueueId = 1;
    public bool $coalesceEnqueue = false;
    public bool $throwOnEnqueue = false;

    /** @var list<string> Types that {@see enqueue()} was called for, in order. */
    public array $enqueued = [];

    public function setLatest(Order58SyncType $type, SyncRun $run): void
    {
        $this->latest[$type->value] = $run;
    }

    public function setLastSuccess(Order58SyncType $type, DateTimeImmutable $at): void
    {
        $this->lastSuccess[$type->value] = $at;
    }

    public function latestByType(): array
    {
        return $this->latest;
    }

    public function lastSuccessfulAtByType(): array
    {
        return $this->lastSuccess;
    }

    public function enqueue(Order58SyncType $type, ?int $scopeRef, ?int $requestedByAdminId, DateTimeImmutable $now): ?int
    {
        if ($this->throwOnEnqueue) {
            throw new \RuntimeException('enqueue failed (test)');
        }
        if ($this->coalesceEnqueue) {
            return null; // an active run already exists → coalesced
        }
        $this->enqueued[] = $type->value;

        return $this->nextEnqueueId++;
    }

    public function hasActive(Order58SyncType $type, ?int $scopeRef): bool
    {
        throw self::unused();
    }

    public function findClaimable(int $limit, DateTimeImmutable $now): array
    {
        throw self::unused();
    }

    public function claim(int $id, DateTimeImmutable $now): bool
    {
        throw self::unused();
    }

    public function saveProgress(int $id, SyncProgress $progress, DateTimeImmutable $now): void
    {
        throw self::unused();
    }

    public function finish(int $id, SyncRunStatus $status, SyncProgress $progress, ?string $errorCode, ?string $errorMessage, DateTimeImmutable $now): void
    {
        throw self::unused();
    }

    public function requeue(int $id, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage, DateTimeImmutable $now): void
    {
        throw self::unused();
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        throw self::unused();
    }

    public function findById(int $id): ?SyncRun
    {
        throw self::unused();
    }

    public function latestHealth(): ?SyncRun
    {
        throw self::unused();
    }

    public function recent(int $limit): array
    {
        throw self::unused();
    }

    public function countActive(): int
    {
        throw self::unused();
    }

    private static function unused(): BadMethodCallException
    {
        return new BadMethodCallException('Not used by the freshness service.');
    }
}
