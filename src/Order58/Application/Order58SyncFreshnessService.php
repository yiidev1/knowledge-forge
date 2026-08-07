<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncFreshness;
use App\Order58\Domain\SyncFreshnessState;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Domain\SyncRunStatus;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;

/**
 * The ONE place that decides how fresh each Order58 sync type's data is (Stores / Knowledge / Agents / Rules),
 * from `integration_sync_runs` — so no template re-derives it. Each type is independent: a Rules failure never
 * changes Knowledge freshness, and a failed run never overwrites the last *successful* time.
 *
 * Derived from two bounded queries (no per-type N+1): the latest run per type (the last attempt + any in-flight
 * run) and the latest successful completion time per type. State precedence: never → syncing → failed →
 * warning → fresh (last success within the window) → stale.
 */
final readonly class Order58SyncFreshnessService
{
    /** Data older than this since the last success is Stale (a duration, so it is DST-agnostic). */
    private const DEFAULT_STALE_AFTER_SECONDS = 26 * 3600;

    /** Daily-schedule local hour (America/New_York) per type; types absent here have no daily schedule. */
    private const DAILY_HOUR = [
        Order58SyncType::Knowledge->value => 2,
        Order58SyncType::Rules->value => 3,
    ];

    public function __construct(
        private SyncRunRepositoryInterface $runs,
        private ClockInterface $clock,
        private AppTimeZone $appTimeZone,
        private int $staleAfterSeconds = self::DEFAULT_STALE_AFTER_SECONDS,
    ) {}

    /**
     * Freshness for every top-level type, keyed by {@see Order58SyncType::value}.
     *
     * @return array<string, SyncFreshness>
     */
    public function all(): array
    {
        $latest = $this->runs->latestByType();
        $lastSuccess = $this->runs->lastSuccessfulAtByType();

        $out = [];
        foreach ([Order58SyncType::Stores, Order58SyncType::Knowledge, Order58SyncType::Agents, Order58SyncType::Rules] as $type) {
            $out[$type->value] = $this->build($type, $latest[$type->value] ?? null, $lastSuccess[$type->value] ?? null);
        }

        return $out;
    }

    private function build(Order58SyncType $type, ?SyncRun $latest, ?DateTimeImmutable $lastSuccessAt): SyncFreshness
    {
        $syncing = $latest !== null && $latest->status()->isActive();
        $attemptStatus = $latest?->status();
        $attemptAt = $latest === null ? null : ($latest->completedAt() ?? $latest->startedAt() ?? $latest->createdAt());

        $state = match (true) {
            $latest === null => SyncFreshnessState::NeverSynced,
            $syncing => SyncFreshnessState::Syncing,
            $attemptStatus === SyncRunStatus::Failed => SyncFreshnessState::Failed,
            $attemptStatus === SyncRunStatus::CompletedWithWarnings => SyncFreshnessState::Warning,
            $lastSuccessAt !== null && $this->withinWindow($lastSuccessAt) => SyncFreshnessState::Fresh,
            default => SyncFreshnessState::Stale,
        };

        $hour = self::DAILY_HOUR[$type->value] ?? null;
        $nextScheduledAt = $hour === null ? null : $this->appTimeZone->nextDailyOccurrence($this->clock->now(), $hour);

        return new SyncFreshness($type, $state, $lastSuccessAt, $attemptAt, $attemptStatus, $syncing, $nextScheduledAt);
    }

    private function withinWindow(DateTimeImmutable $lastSuccessAt): bool
    {
        return ($this->clock->now()->getTimestamp() - $lastSuccessAt->getTimestamp()) <= $this->staleAfterSeconds;
    }
}
