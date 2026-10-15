<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * How many of each store's orders currently have a topic on them.
 *
 * **Orders, not topics.** The first version of this counted distinct topic ids, which answered "how
 * many different topics is this store practising" — a fair question, and not the one a directory card
 * is read for. A store with three orders all assigned to "Test2" showed a 1 while its own page showed
 * three assigned rows, and the two numbers appeared to disagree about the same data.
 *
 * So it counts assigned orders. Three orders under one topic is three; three orders under three topics
 * is also three; an order recorded four times is still one.
 *
 * Batched for the whole page, exactly like {@see StoreAudioCountsInterface}, because the alternative is
 * one query per card and a directory of 200 stores is a directory of 200 queries.
 */
interface StoreTopicAssignmentCountsInterface
{
    /**
     * @param list<int> $sourceIds the stores being rendered
     *
     * @return array<int, int> store source id => orders carrying a topic; stores with none are absent
     */
    public function assignedOrdersFor(array $sourceIds): array;
}
