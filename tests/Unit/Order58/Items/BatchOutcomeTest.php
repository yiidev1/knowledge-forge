<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Application\Items\ItemSyncResult;
use App\Order58\Domain\Items\BatchOutcome;
use App\Order58\Domain\Items\EnrolmentOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide what an operation came to.
 *
 * ## What is really being guarded
 *
 * That "finished" cannot be said while work is pending, and that an operation which covered less than
 * it meant to can never call itself complete. Both are one-line mistakes to make and neither is visible
 * until somebody trusts a figure that was wrong.
 */
final class BatchOutcomeTest extends TestCase
{
    /** The rule everything rests on: pending work means running, whatever else is true. */
    public function testAnyUnsettledEnrolmentKeepsTheBatchRunning(): void
    {
        $this->assertSame(BatchOutcome::Running, BatchOutcome::decide(1, 0, false, false));
        $this->assertSame(BatchOutcome::Running, BatchOutcome::decide(1, 3, false, false));
        // Even a scope known to be short is still running while anything is outstanding.
        $this->assertSame(BatchOutcome::Running, BatchOutcome::decide(1, 0, true, false));
    }

    public function testEverythingSettledAndNothingFailedIsCompleted(): void
    {
        $this->assertSame(BatchOutcome::Completed, BatchOutcome::decide(0, 0, false, false));
    }

    public function testOneFailureMakesItPartial(): void
    {
        $this->assertSame(BatchOutcome::Partial, BatchOutcome::decide(0, 1, false, false));
    }

    /**
     * The case that is easy to get wrong.
     *
     * An Orders Sync that ran out of time enrolled only part of its range. Nothing is unsettled,
     * because the orders it never reached were never enrolled — so a naive rule would call this
     * complete and claim a range nobody finished.
     */
    public function testATruncatedScopeIsPartialEvenWithNothingPendingAndNothingFailed(): void
    {
        $this->assertSame(BatchOutcome::Partial, BatchOutcome::decide(0, 0, true, false));
    }

    /** An Orders Sync that failed outright never had a scope at all. */
    public function testAFailedOrdersSyncOutranksEverythingElse(): void
    {
        $this->assertSame(BatchOutcome::Failed, BatchOutcome::decide(0, 0, false, true));
        $this->assertSame(BatchOutcome::Failed, BatchOutcome::decide(5, 0, false, true));
    }

    public function testOnlyRunningIsUnfinished(): void
    {
        $this->assertFalse(BatchOutcome::Running->isFinished());
        $this->assertTrue(BatchOutcome::Completed->isFinished());
        $this->assertTrue(BatchOutcome::Partial->isFinished());
        $this->assertTrue(BatchOutcome::Failed->isFinished());
    }

    // ------------------------------------------------------------------ enrolment mapping

    /**
     * Only a terminal result settles an enrolment.
     *
     * `Busy` means another caller holds the order and will settle it; `Unavailable` means nothing was
     * attempted. Settling on either would close a batch over work that has not happened — which is the
     * one failure mode this whole design exists to prevent.
     *
     * @dataProvider nonTerminalResults
     */
    public function testANonTerminalResultSettlesNothing(ItemSyncResult $result): void
    {
        $this->assertNull(EnrolmentOutcome::fromSyncResult($result));
    }

    /** @return iterable<string, array{ItemSyncResult}> */
    public static function nonTerminalResults(): iterable
    {
        yield 'busy' => [ItemSyncResult::Busy];
        yield 'unavailable' => [ItemSyncResult::Unavailable];
    }

    /**
     * @dataProvider terminalResults
     */
    public function testATerminalResultMapsToItsEnrolmentOutcome(
        ItemSyncResult $result,
        EnrolmentOutcome $expected,
    ): void {
        $this->assertSame($expected, EnrolmentOutcome::fromSyncResult($result));
    }

    /** @return iterable<string, array{ItemSyncResult, EnrolmentOutcome}> */
    public static function terminalResults(): iterable
    {
        yield 'synced' => [ItemSyncResult::Synced, EnrolmentOutcome::Synced];
        yield 'empty' => [ItemSyncResult::Empty, EnrolmentOutcome::Empty];
        yield 'unchanged' => [ItemSyncResult::Unchanged, EnrolmentOutcome::Unchanged];
        yield 'failed' => [ItemSyncResult::Failed, EnrolmentOutcome::Failed];
    }

    /** Only a failure counts against a batch's verdict. */
    public function testOnlyFailedCountsAsAFailure(): void
    {
        $this->assertTrue(EnrolmentOutcome::Failed->isFailure());

        foreach ([EnrolmentOutcome::Synced, EnrolmentOutcome::Empty, EnrolmentOutcome::Unchanged, EnrolmentOutcome::Skipped] as $outcome) {
            $this->assertFalse($outcome->isFailure(), $outcome->value . ' must not count as a failure.');
        }
    }

    /** `Skipped` exists so "already current" is not reported as a request that never happened. */
    public function testSkippedIsNotReachableFromAProviderResult(): void
    {
        foreach (ItemSyncResult::cases() as $result) {
            $this->assertNotSame(
                EnrolmentOutcome::Skipped,
                EnrolmentOutcome::fromSyncResult($result),
                'Skipped describes an order nobody asked about; no request can produce it.',
            );
        }
    }
}
