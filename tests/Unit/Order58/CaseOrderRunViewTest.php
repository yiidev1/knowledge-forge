<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Order58\Web\CaseOrders\CaseOrderRunView;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function str_contains;

/**
 * The contract between the run and the script that draws it.
 *
 * ## Why these field names are worth a test
 *
 * The dialog is fed from two endpoints — the status poll and, now, the synchronisation POST's own
 * reply — and it renders whichever arrives by reading named fields. A field that is missing does not
 * raise anything: `undefined` becomes a blank badge or a count that silently reads zero, which is
 * indistinguishable from a run that genuinely did nothing. That is the failure this guards.
 */
final class CaseOrderRunViewTest extends TestCase
{
    /** Every field `order58-case-orders.js` reads off a run, named exactly as it reads it. */
    private const READ_BY_THE_SCRIPT = [
        'id', 'statusLabel', 'modifier', 'percent', 'finished', 'running',
        'ordersReceived', 'ordersInserted', 'ordersUpdated', 'ordersUnchanged',
        'itemsImported', 'failures', 'message', 'canReimport', 'allowanceSpent',
    ];

    public function testItCarriesEveryFieldTheScriptReads(): void
    {
        $described = CaseOrderRunView::describe($this->aRun());

        self::assertIsArray($described);

        foreach (self::READ_BY_THE_SCRIPT as $field) {
            self::assertArrayHasKey($field, $described, 'The panel reads ' . $field . '.');
        }
    }

    /** A completed run is reported as finished and not running — the two the dialog branches on. */
    public function testAFinishedRunIsNotReportedAsRunning(): void
    {
        $described = CaseOrderRunView::describe($this->aRun(CaseOrderSyncStatus::Completed));

        self::assertIsArray($described);
        self::assertTrue($described['finished']);
        self::assertFalse($described['running']);
    }

    /** And a claimed one is, so a joined run is watched rather than reported as over. */
    public function testAClaimedRunIsReportedAsRunning(): void
    {
        $described = CaseOrderRunView::describe($this->aRun(CaseOrderSyncStatus::Claimed));

        self::assertIsArray($described);
        self::assertFalse($described['finished']);
        self::assertTrue($described['running']);
    }

    /** No run is null rather than an empty shape, so "nothing started" cannot render as zero counts. */
    public function testNoRunDescribesNothing(): void
    {
        self::assertNull(CaseOrderRunView::describe(null));
    }

    /**
     * Both endpoints answer with this one shape.
     *
     * Checked in the source because the alternative is two copies drifting apart — which is precisely
     * how the panel came to be fed a run it could not read.
     */
    public function testBothEndpointsUseTheSharedShape(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Order58/Web/CaseOrders/';

        foreach (['StatusAction.php', 'SyncAction.php'] as $file) {
            self::assertTrue(
                str_contains((string) file_get_contents($root . $file), 'CaseOrderRunView::describe('),
                $file . ' must describe a run through the shared view.',
            );
        }
    }

    private function aRun(CaseOrderSyncStatus $status = CaseOrderSyncStatus::Completed): CaseOrderSyncRun
    {
        return new CaseOrderSyncRun(
            id: 68,
            businessDay: '2026-10-26',
            timezone: 'America/New_York',
            status: $status,
            stage: null,
            triggerSource: 'manual',
            startedAt: new DateTimeImmutable('2026-10-26 09:13:35'),
            fetchedAt: null,
            completedAt: $status === CaseOrderSyncStatus::Completed
                ? new DateTimeImmutable('2026-10-26 09:13:42')
                : null,
            leaseExpiresAt: null,
            httpStatus: 200,
            responseBytes: 1024,
            ordersReceived: 200,
            ordersInserted: 200,
            ordersUpdated: 0,
            ordersSkipped: 0,
            ordersUnchanged: 0,
            itemsImported: 723,
            failures: 0,
            errorSummary: null,
            hasStagedPayload: false,
        );
    }
}
