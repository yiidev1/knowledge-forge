<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

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

    /*
     * The `fromSyncResult()` cases went with the endpoint in Stage 7a.
     *
     * They described the translation from a per-order items REQUEST's result into a batch enrolment,
     * and there is no such request any more — an order's lines arrive inside the order. Deleted rather
     * than rewritten against the enum directly, because what they were protecting was the translation
     * itself: that `Busy` and `Unavailable` settled nothing. With no caller, there is nothing to get
     * wrong.
     *
     * What still matters about the enum is below, and is unchanged: which outcomes count as failures,
     * and that `Skipped` means "nobody asked", which the batch repository sets directly.
     */

    /** Only a failure counts against a batch's verdict. */
    public function testOnlyFailedCountsAsAFailure(): void
    {
        $this->assertTrue(EnrolmentOutcome::Failed->isFailure());

        foreach ([EnrolmentOutcome::Synced, EnrolmentOutcome::Empty, EnrolmentOutcome::Unchanged, EnrolmentOutcome::Skipped] as $outcome) {
            $this->assertFalse($outcome->isFailure(), $outcome->value . ' must not count as a failure.');
        }
    }

    /**
     * `Skipped` means "nobody asked", and nothing else produces it.
     *
     * It used to be asserted against every provider result. With the provider request gone, the claim
     * worth keeping is that it stays distinct from the four outcomes that DO describe work: an order
     * that was already current must never be counted as one that was fetched, which is what would
     * make a batch report requests it never made.
     */
    public function testSkippedIsDistinctFromEveryOutcomeThatDescribesWork(): void
    {
        foreach ([
            EnrolmentOutcome::Synced,
            EnrolmentOutcome::Empty,
            EnrolmentOutcome::Unchanged,
            EnrolmentOutcome::Failed,
        ] as $didWork) {
            $this->assertNotSame(EnrolmentOutcome::Skipped, $didWork);
        }

        $this->assertFalse(EnrolmentOutcome::Skipped->isFailure());
    }
}
