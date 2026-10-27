<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * One store's Case Order call recordings, counted three ways that must not be confused.
 *
 * - **orders**: Case Orders with at least one current recording link — the card's headline, and the
 *   number of rows its practice page lists. An order recorded as three files is one order.
 * - **calls**: call sessions behind those orders. One order can hold a call back or a transfer.
 * - **files**: current WAV links. One call can be split across several.
 *
 * All three come from the stored metadata. None of them asks the provider whether a file still exists:
 * a store card is drawn without a single request leaving the server.
 */
final readonly class StoreCaseRecordingCounts
{
    public function __construct(
        public int $orders,
        public int $calls,
        public int $files,
    ) {}
}
