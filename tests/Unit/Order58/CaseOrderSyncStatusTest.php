<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use Codeception\Test\Unit;
use DateTimeImmutable;

use function file_get_contents;
use function preg_match;
use function str_contains;

/**
 * The stages of a case order synchronisation.
 *
 * ## Why the interesting method is `hasSpentTheAllowance()`
 *
 * The provider permits one request a day. After a failure the only question that costs anything is
 * whether that request was actually made — it decides whether the operator tries again now or waits
 * until tomorrow — and the stage is the only record of it. A status wrongly reporting the allowance as
 * unspent would invite a second request, which is a breach of the client's agreement rather than an
 * inconvenience; one wrongly reporting it as spent would waste a day.
 */
final class CaseOrderSyncStatusTest extends Unit
{
    /** Claimed is the only stage before the socket. */
    public function testOnlyTheClaimedStageHasNotSpentTheAllowance(): void
    {
        $this->assertFalse(CaseOrderSyncStatus::Claimed->hasSpentTheAllowance());

        foreach ([
            CaseOrderSyncStatus::Fetched,
            CaseOrderSyncStatus::Processing,
            CaseOrderSyncStatus::Completed,
            CaseOrderSyncStatus::Failed,
        ] as $status) {
            $this->assertTrue(
                $status->hasSpentTheAllowance(),
                $status->value . ' comes after the request, so the day is spent',
            );
        }
    }

    /**
     * A failure before the socket spent nothing, and only the run can say so.
     *
     * The case that caught this: `FAILED` is reachable from either side of the request, so the status
     * alone reports every failure as having spent the day. An operator told that would wait until
     * tomorrow for a run that never reached the provider — and the opposite mistake would invite a
     * second request to somebody who permits one.
     */
    public function testARunThatFailedBeforeTheRequestReportsTheAllowanceAsUnspent(): void
    {
        $this->assertFalse(
            $this->runAt(CaseOrderSyncStatus::Failed, CaseOrderSyncRun::STAGE_REQUEST)->allowanceSpent(),
            'nothing was sent',
        );

        $this->assertTrue(
            $this->runAt(CaseOrderSyncStatus::Failed, CaseOrderSyncRun::STAGE_FETCH)->allowanceSpent(),
            'the provider answered, even if the answer was a refusal',
        );

        $this->assertTrue(
            $this->runAt(CaseOrderSyncStatus::Failed, CaseOrderSyncRun::STAGE_IMPORT)->allowanceSpent(),
            'the response arrived; the failure was local',
        );

        // And every stage that is not a failure answers from the stage alone, as before.
        $this->assertFalse($this->runAt(CaseOrderSyncStatus::Claimed, null)->allowanceSpent());
        $this->assertTrue($this->runAt(CaseOrderSyncStatus::Fetched, null)->allowanceSpent());
        $this->assertTrue($this->runAt(CaseOrderSyncStatus::Completed, null)->allowanceSpent());
    }

    /** One run, at a given stage. Only the two fields under test carry anything. */
    private function runAt(CaseOrderSyncStatus $status, ?string $stage): CaseOrderSyncRun
    {
        return new CaseOrderSyncRun(
            id: 1,
            businessDay: '2026-10-26',
            timezone: 'America/New_York',
            status: $status,
            stage: $stage,
            triggerSource: 'manual',
            startedAt: new DateTimeImmutable('2026-10-26 09:00:00'),
            fetchedAt: null,
            completedAt: null,
            leaseExpiresAt: null,
            httpStatus: null,
            responseBytes: null,
            ordersReceived: 0,
            ordersInserted: 0,
            ordersUpdated: 0,
            ordersSkipped: 0,
            ordersUnchanged: 0,
            itemsImported: 0,
            failures: 0,
            errorSummary: null,
            hasStagedPayload: false,
        );
    }

    public function testOnlyCompletedAndFailedAreFinished(): void
    {
        $this->assertTrue(CaseOrderSyncStatus::Completed->isFinished());
        $this->assertTrue(CaseOrderSyncStatus::Failed->isFinished());

        $this->assertFalse(CaseOrderSyncStatus::Claimed->isFinished());
        $this->assertFalse(CaseOrderSyncStatus::Fetched->isFinished());
        $this->assertFalse(CaseOrderSyncStatus::Processing->isFinished());
    }

    /** The bar only ever moves forward, which is what makes it readable as progress. */
    public function testThePercentNeverGoesBackwards(): void
    {
        $previous = -1;

        foreach ([
            CaseOrderSyncStatus::Claimed,
            CaseOrderSyncStatus::Fetched,
            CaseOrderSyncStatus::Processing,
            CaseOrderSyncStatus::Completed,
        ] as $status) {
            $this->assertGreaterThan($previous, $status->percent(), $status->value);
            $previous = $status->percent();
        }

        $this->assertSame(100, CaseOrderSyncStatus::Completed->percent());
    }

    /**
     * Every status names a badge variant the stylesheet actually defines.
     *
     * Checked against the file rather than against a list copied from it, because a copy is what would
     * go stale. An undefined variant is invisible in review and renders as an unstyled chip — the sort
     * of thing nobody notices until a screenshot.
     */
    public function testEveryModifierIsAVariantTheStylesheetDefines(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../assets/main/admin.css');

        foreach (CaseOrderSyncStatus::cases() as $status) {
            $this->assertTrue(
                // `.badge--x`, with the leading dot and nothing before it: `a2t-badge--queued` is a
                // different class in a different block, and matching it would pass on a variant the
                // plain `badge` has never had.
                (bool) preg_match('/(^|[^a-z-])\.badge--' . $status->modifier() . '\b/m', $css),
                $status->value . ' wears .badge--' . $status->modifier() . ', which admin.css must define',
            );
        }
    }

    /** The stored values reach the database CHECK constraint, so they are a contract. */
    public function testTheStoredValuesMatchTheDatabaseConstraint(): void
    {
        $migration = (string) file_get_contents(
            __DIR__ . '/../../../src/Migration/M261026120000CreateCaseOrderSyncRuns.php',
        );

        foreach (CaseOrderSyncStatus::cases() as $status) {
            $this->assertTrue(
                str_contains($migration, "'" . $status->value . "'"),
                $status->value . ' must appear in chk_case_order_sync_runs_status',
            );
        }
    }
}
