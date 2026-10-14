<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * The topic one source order is being practised under.
 *
 * Keyed on `(storeSourceId, sourceOrderId)` — the pair, never the order id alone, because Order58
 * publishes no contract that order ids are unique across stores. The topic's name travels with the
 * assignment so a list can render without a second lookup, and so an archived topic still reads as
 * what it was.
 */
final readonly class TopicAssignment
{
    public function __construct(
        public int $storeSourceId,
        public int $sourceOrderId,
        public int $topicId,
        public string $topicName,
        public TopicStatus $topicStatus,
    ) {}
}
