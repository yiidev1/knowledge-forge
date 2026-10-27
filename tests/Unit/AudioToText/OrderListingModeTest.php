<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\OrderListingMode;
use Codeception\Test\Unit;

/**
 * Which population a store listing is a list of.
 *
 * One boolean reaches this from the environment — `ORDER58_CASE_ORDER_PRIMARY` — and these cases pin
 * which way round it means, because that is the part a reader cannot check by looking at the enum. A
 * reversed mapping would be invisible in review and would show every store's page listing the wrong
 * records, with no error anywhere.
 */
final class OrderListingModeTest extends Unit
{
    public function testTrueMeansOrdersAreTheRows(): void
    {
        $mode = OrderListingMode::fromCaseOrderPrimary(true);

        $this->assertSame(OrderListingMode::CaseOrderPrimary, $mode);
        $this->assertTrue($mode->listsOrdersWithoutRecordings());
        $this->assertFalse($mode->listsRecordingsWithoutOrders());
    }

    public function testFalseMeansRecordingsAreTheRows(): void
    {
        $mode = OrderListingMode::fromCaseOrderPrimary(false);

        $this->assertSame(OrderListingMode::RecordingPrimary, $mode);
        $this->assertFalse($mode->listsOrdersWithoutRecordings());
        $this->assertTrue($mode->listsRecordingsWithoutOrders());
    }

    /**
     * The two modes never agree about an unmatched record.
     *
     * Stated as its own case because it is the invariant the whole setting exists for: each mode shows
     * the records of its own population that have no counterpart, and hides the other's. A change that
     * made both answer the same way to either question would have made the setting do nothing.
     */
    public function testTheTwoModesNeverAgreeAboutAnUnmatchedRecord(): void
    {
        foreach (OrderListingMode::cases() as $mode) {
            $this->assertNotSame(
                $mode->listsOrdersWithoutRecordings(),
                $mode->listsRecordingsWithoutOrders(),
                $mode->value . ' must include exactly one population\'s unmatched records',
            );
        }
    }

    /** The stored values are part of the contract: they appear in DI and in test fixtures. */
    public function testTheStoredValuesAreStable(): void
    {
        $this->assertSame('case-order', OrderListingMode::CaseOrderPrimary->value);
        $this->assertSame('recording', OrderListingMode::RecordingPrimary->value);
    }
}
