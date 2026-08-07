<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Order58SyncFreshnessService;
use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncFreshnessState;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunStatus;
use App\Shared\Application\Time\AppTimeZone;
use App\Tests\Support\Fake\Order58\InMemorySyncRunRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The per-type freshness derivation: each Order58 sync type is independent, the state maps correctly
 * (never/syncing/failed/warning/fresh/stale), and a failed run never overwrites the last *successful* time.
 */
final class Order58SyncFreshnessServiceTest extends Unit
{
    private InMemorySyncRunRepository $runs;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->runs = new InMemorySyncRunRepository();
        $this->clock = new MutableClock('2026-08-07 12:00:00');
    }

    public function testStatesAreIndependentPerTypeAndFailedNeverOverwritesLastSuccess(): void
    {
        // Rules: an earlier SUCCESS then a later FAILURE. State = Failed, but last-success is preserved.
        $success = $this->at('-1 hour');
        $this->runs->setLatest(Order58SyncType::Rules, $this->makeRun(Order58SyncType::Rules, SyncRunStatus::Failed, $this->at('-5 minutes')));
        $this->runs->setLastSuccess(Order58SyncType::Rules, $success);

        // Knowledge: a recent success → Fresh (independent of the Rules failure).
        $this->runs->setLatest(Order58SyncType::Knowledge, $this->makeRun(Order58SyncType::Knowledge, SyncRunStatus::Completed, $this->at('-30 minutes')));
        $this->runs->setLastSuccess(Order58SyncType::Knowledge, $this->at('-30 minutes'));

        // Stores: an in-flight run → Syncing.
        $this->runs->setLatest(Order58SyncType::Stores, $this->makeRun(Order58SyncType::Stores, SyncRunStatus::Running, null));

        // Agents: nothing at all → Never synced.

        $all = $this->service()->all();

        assertSame(SyncFreshnessState::Failed, $all['rules']->state);
        assertSame($success->getTimestamp(), $all['rules']->lastSuccessAt?->getTimestamp(), 'a later failure must not erase last-success');
        assertTrue($all['rules']->hasEverSucceeded());

        assertSame(SyncFreshnessState::Fresh, $all['knowledge']->state, 'Knowledge is unaffected by the Rules failure');
        assertSame(SyncFreshnessState::Syncing, $all['stores']->state);
        assertSame(SyncFreshnessState::NeverSynced, $all['agents']->state);
    }

    public function testWarningStateWhenLatestCompletedWithWarnings(): void
    {
        $this->runs->setLatest(Order58SyncType::Rules, $this->makeRun(Order58SyncType::Rules, SyncRunStatus::CompletedWithWarnings, $this->at('-10 minutes')));
        $this->runs->setLastSuccess(Order58SyncType::Rules, $this->at('-10 minutes'));

        assertSame(SyncFreshnessState::Warning, $this->service()->all()['rules']->state);
    }

    public function testStaleWhenLastSuccessIsOlderThanTheWindow(): void
    {
        // Last (and only) success was 48h ago; the default stale window is 26h → Stale.
        $this->runs->setLatest(Order58SyncType::Rules, $this->makeRun(Order58SyncType::Rules, SyncRunStatus::Completed, $this->at('-48 hours')));
        $this->runs->setLastSuccess(Order58SyncType::Rules, $this->at('-48 hours'));

        assertSame(SyncFreshnessState::Stale, $this->service()->all()['rules']->state);
    }

    private function service(): Order58SyncFreshnessService
    {
        return new Order58SyncFreshnessService($this->runs, $this->clock, new AppTimeZone('America/New_York'));
    }

    private function at(string $modifier): DateTimeImmutable
    {
        return $this->clock->now()->modify($modifier);
    }

    private function makeRun(Order58SyncType $type, SyncRunStatus $status, ?DateTimeImmutable $completedAt): SyncRun
    {
        $now = new DateTimeImmutable('2026-08-07 12:00:00', new DateTimeZone('UTC'));

        return new SyncRun(
            1,
            $type,
            null,
            $status,
            1,
            null,
            new SyncProgress(),
            $completedAt,
            $completedAt,
            null,
            null,
            $now,
            $now,
        );
    }
}
