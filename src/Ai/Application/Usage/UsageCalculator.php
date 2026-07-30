<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use function max;

/**
 * Turns raw byte totals into storage and cost figures.
 *
 * Two rules this class exists to enforce, both easy to get wrong by hand:
 *
 * 1. The free allowance is deducted **once from the account total**, not from each vector store. Ten
 *    stores of 0.5 GiB each are 5 GiB total with 1 GiB free — 4 GiB billable — not ten free stores.
 * 2. Everything is computed in full float precision and rounded only for display. Rounding each store's
 *    contribution first and then summing drifts away from the figure OpenAI actually bills.
 *
 * Every number produced here is an ESTIMATE from a point-in-time snapshot. Real storage billing is
 * time-weighted across the day, so a store deleted an hour after this reading still costs something and
 * a store created a minute before it has barely cost anything. The page must never present these as an
 * invoice.
 */
final readonly class UsageCalculator
{
    public function gibFromBytes(int $bytes): float
    {
        return $bytes / UsagePricing::BYTES_PER_GIB;
    }

    /**
     * Storage that is actually chargeable: the total less the free allowance, never below zero.
     */
    public function billableGib(int $totalBytes): float
    {
        return max(0.0, $this->gibFromBytes($totalBytes) - UsagePricing::FREE_STORAGE_GIB);
    }

    public function estimatedDailyStorageCost(int $totalBytes): float
    {
        return $this->billableGib($totalBytes) * UsagePricing::STORAGE_USD_PER_GIB_PER_DAY;
    }

    public function estimatedProjectedStorageCost(int $totalBytes): float
    {
        return $this->estimatedDailyStorageCost($totalBytes) * UsagePricing::PROJECTION_DAYS;
    }

    /**
     * What a number of File Search tool calls would cost.
     *
     * Only usable when a call count is known, which today means an Organization admin key. Without one
     * the page reports the count as unavailable rather than assuming zero — zero would render as "$0.00
     * spent", which is a claim, not an absence.
     */
    public function estimatedFileSearchCost(int $calls): float
    {
        return $calls / 1000 * UsagePricing::FILE_SEARCH_USD_PER_1K_CALLS;
    }

    /**
     * One store's share of the billable cost, apportioned by its share of total bytes.
     *
     * Apportioning rather than charging each store for its own bytes is what keeps the per-store column
     * summing to the account total: the single free GiB has to be spread across the stores somehow, and
     * proportionally is the only split that adds up.
     */
    public function apportionedDailyCost(int $storeBytes, int $totalBytes): float
    {
        if ($totalBytes <= 0) {
            return 0.0;
        }

        return $this->estimatedDailyStorageCost($totalBytes) * ($storeBytes / $totalBytes);
    }
}
