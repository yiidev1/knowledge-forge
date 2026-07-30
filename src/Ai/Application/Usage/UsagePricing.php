<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * OpenAI File Search prices, in one place.
 *
 * Every figure this application calls a "cost" is derived from these constants and nothing else, so
 * there is exactly one file to edit when OpenAI changes a price, and exactly one file to read to find
 * out what the dashboard is claiming.
 *
 * Deliberately NOT here: model input/output token prices. Those vary per model and per tier, they are
 * not part of File Search billing, and a stale hard-coded table would produce a confident wrong number.
 * Token usage is reported as counts only; monetary cost for it comes from the Organization Costs API
 * when an admin key is configured.
 *
 * Prices as published for File Search at the time of writing:
 * - storage: the first 1 GiB is free, then USD 0.10 per GiB per day
 * - tool calls: USD 2.50 per 1,000 File Search calls
 */
final class UsagePricing
{
    /**
     * A binary gigabyte, 2^30 bytes.
     *
     * OpenAI bills File Search storage per binary GB. Using 10^9 here would understate the free
     * allowance by ~7% and overstate every cost derived from it.
     */
    public const BYTES_PER_GIB = 1073741824;

    /** Free storage allowance, applied ONCE to the account total — never per vector store. */
    public const FREE_STORAGE_GIB = 1.0;

    public const STORAGE_USD_PER_GIB_PER_DAY = 0.10;

    public const FILE_SEARCH_USD_PER_1K_CALLS = 2.50;

    /** The window used for the "projected 30 days" figure. */
    public const PROJECTION_DAYS = 30;

    public const CURRENCY = 'USD';
}
