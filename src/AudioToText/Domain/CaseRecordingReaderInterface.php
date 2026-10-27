<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The Case Order call recordings of the orders on one page of a store listing.
 *
 * One query for the page, keyed by the order id each row shows — the same shape the demo links, topics
 * and verifications beside it use, and for the same reason: fifteen rows must not be fifteen queries.
 */
interface CaseRecordingReaderInterface
{
    /**
     * @param list<string> $orderIds the order ids the page's rows show; anything not an order number is ignored
     *
     * @return array<string, list<CaseRecording>> current recordings per order id, in listing order;
     *                                            an order with none is absent
     */
    public function forOrders(int $storeSourceId, array $orderIds): array;
}
