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
    ) {}

    public function isArchived(): bool
    {
        return $this->status === TopicStatus::Archived;
    }
}
