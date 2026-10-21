<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * One training topic, as the application reads it.
 *
 * `assignedOrders` is a count carried alongside rather than a collection: the list page shows it, and
 * loading the orders themselves to display a number would be one query per row.
 */
final readonly class Topic
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public TopicStatus $status,
        public int $assignedOrders = 0,
        public ?string $createdAt = null,
        /**
         * The one store this topic belongs to, or null for every store.
         *
         * Order58's own store id — the same value `/order-testing/store/{sourceId}` carries and the
         * same one `practice_order_topics` records an assignment under. Null is not a sentinel to be
         * migrated to: it is the absence of a restriction, which is what every topic had before.
         */
        public ?int $storeSourceId = null,
        /**
         * That store's name, resolved when the topic was read, or null when it is global.
         *
         * Carried rather than looked up per row — the listing shows it for every topic on the page,
         * and a lookup per row is the N+1 this avoids. Also null when the store has left the mirror,
         * which the page renders as the id rather than as nothing.
         */
        public ?string $storeName = null,
    ) {}

    public function isGlobal(): bool
    {
        return $this->storeSourceId === null;
    }

    public function isArchived(): bool
    {
        return $this->status === TopicStatus::Archived;
    }
}
