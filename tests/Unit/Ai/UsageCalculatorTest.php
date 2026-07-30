<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Usage\UsageCalculator;
use App\Ai\Application\Usage\UsagePricing;
use App\Ai\Application\Usage\UsageStoreRow;
use App\Ai\Application\Usage\UsageTotals;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertEqualsWithDelta;
use function PHPUnit\Framework\assertSame;

/**
 * The cost arithmetic, including the two rules that are easy to get wrong by hand: binary gigabytes,
 * and a free allowance that applies once to the account rather than once per store.
 */
final class UsageCalculatorTest extends Unit
{
    private const GIB = 1073741824;

    private UsageCalculator $calculator;

    protected function _before(): void
    {
        $this->calculator = new UsageCalculator();
    }

    public function testUsesBinaryGigabytes(): void
    {
        assertSame(1073741824, UsagePricing::BYTES_PER_GIB);
        assertEqualsWithDelta(1.0, $this->calculator->gibFromBytes(self::GIB), 0.000001);
        // A decimal GB would read as 1.0 here; a binary one must not.
        assertEqualsWithDelta(0.931323, $this->calculator->gibFromBytes(1_000_000_000), 0.000001);
    }

    public function testStorageBelowTheAllowanceIsFree(): void
    {
        assertSame(0.0, $this->calculator->billableGib(0));
        assertSame(0.0, $this->calculator->billableGib((int) (self::GIB * 0.5)));
        assertSame(0.0, $this->calculator->estimatedDailyStorageCost((int) (self::GIB * 0.9)));
    }

    public function testAllowanceIsNeverNegativeAtTheBoundary(): void
    {
        assertSame(0.0, $this->calculator->billableGib(self::GIB));
    }

    public function testBillableStorageIsTheExcessOverTheAllowance(): void
    {
        assertEqualsWithDelta(2.0, $this->calculator->billableGib(self::GIB * 3), 0.000001);
    }

    public function testDailyAndProjectedCost(): void
    {
        // 3 GiB total − 1 GiB free = 2 GiB billable × $0.10 = $0.20/day.
        assertEqualsWithDelta(0.20, $this->calculator->estimatedDailyStorageCost(self::GIB * 3), 0.000001);
        assertEqualsWithDelta(6.00, $this->calculator->estimatedProjectedStorageCost(self::GIB * 3), 0.000001);
    }

    public function testFileSearchCallCost(): void
    {
        assertEqualsWithDelta(2.50, $this->calculator->estimatedFileSearchCost(1000), 0.000001);
        assertEqualsWithDelta(0.25, $this->calculator->estimatedFileSearchCost(100), 0.000001);
        assertSame(0.0, $this->calculator->estimatedFileSearchCost(0));
    }

    /**
     * The rule most likely to be got wrong: four half-gigabyte stores are 2 GiB with ONE free GiB, so
     * 1 GiB is billable. Applying the allowance per store would make all four free and report $0.00.
     */
    public function testFreeAllowanceAppliesOnceToTheTotalNotPerStore(): void
    {
        $half = (int) (self::GIB / 2);
        $stores = [
            $this->store('vs_1', $half),
            $this->store('vs_2', $half),
            $this->store('vs_3', $half),
            $this->store('vs_4', $half),
        ];

        $totals = UsageTotals::from($stores, $this->calculator);

        assertEqualsWithDelta(2.0, $totals->totalGib, 0.000001);
        assertEqualsWithDelta(1.0, $totals->billableGib, 0.000001);
        assertEqualsWithDelta(0.10, $totals->estimatedDailyCost, 0.000001);
    }

    public function testTotalsAggregateBytesAndFileCounts(): void
    {
        $stores = [
            $this->store('vs_1', 100, new VectorStoreFileCounts(3, 2, 1, 0, 0)),
            $this->store('vs_2', 250, new VectorStoreFileCounts(4, 4, 0, 0, 0)),
        ];

        $totals = UsageTotals::from($stores, $this->calculator);

        assertSame(2, $totals->storeCount);
        assertSame(350, $totals->totalUsageBytes);
        assertSame(7, $totals->fileCounts->total);
        assertSame(6, $totals->fileCounts->completed);
        assertSame(1, $totals->fileCounts->inProgress);
    }

    /**
     * A status this code has never seen must still be counted. Dropping it would make the per-status
     * figures quietly stop summing to the store count.
     */
    public function testUnknownStoreStatusIsBucketedNotDropped(): void
    {
        $stores = [
            $this->store('vs_1', 0, null, 'completed'),
            $this->store('vs_2', 0, null, 'something_new'),
        ];

        $totals = UsageTotals::from($stores, $this->calculator);

        assertSame(2, $totals->storeCount);
        assertSame(1, $totals->storesWithStatus('completed'));
        assertSame(1, $totals->storesWithStatus('something_new'));
    }

    /**
     * Per-store contributions must sum back to the account total, which is why the free allowance is
     * apportioned rather than charged per store.
     */
    public function testApportionedCostsSumToTheAccountTotal(): void
    {
        $total = self::GIB * 4;
        $a = (int) (self::GIB * 3);
        $b = self::GIB;

        $sum = $this->calculator->apportionedDailyCost($a, $total)
            + $this->calculator->apportionedDailyCost($b, $total);

        assertEqualsWithDelta($this->calculator->estimatedDailyStorageCost($total), $sum, 0.000001);
    }

    public function testApportionmentHandlesAnEmptyAccount(): void
    {
        assertSame(0.0, $this->calculator->apportionedDailyCost(0, 0));
    }

    private function store(
        string $id,
        int $bytes,
        ?VectorStoreFileCounts $counts = null,
        string $status = 'completed',
    ): UsageStoreRow {
        return new UsageStoreRow(
            id: $id,
            name: $id,
            status: $status,
            usageBytes: $bytes,
            fileCounts: $counts ?? VectorStoreFileCounts::zero(),
            createdAt: null,
            lastActiveAt: null,
            expiresAt: null,
        );
    }
}
