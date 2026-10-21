<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/** One selectable store: the id a topic is stored against, and the name a person reads. */
final readonly class StoreOption
{
    public function __construct(
        public int $sourceId,
        public string $name,
        /** Shown beside the name so two stores called the same thing can be told apart. */
        public ?string $company = null,
    ) {}

    public function label(): string
    {
        return $this->company === null || $this->company === '' || $this->company === $this->name
            ? $this->name . ' · #' . $this->sourceId
            : $this->name . ' — ' . $this->company . ' · #' . $this->sourceId;
    }
}
