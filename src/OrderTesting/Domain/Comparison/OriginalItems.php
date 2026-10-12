<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * The original order's line items, plus whether they may be believed.
 *
 * The two travel together on purpose. An empty `$items` means nothing on its own — it is either an
 * order the provider says has no lines, or an order nobody has fetched yet — and the only way to stop
 * callers guessing is to make them carry the answer alongside the list.
 */
final readonly class OriginalItems
{
    /** @param list<NormalizedItem> $items in the provider's own order, `position` ascending */
    private function __construct(
        public ItemAvailability $availability,
        public array $items,
        /** When the items being shown were last successfully fetched. */
        public ?string $syncedAt = null,
    ) {}

    /**
     * @param list<NormalizedItem> $items
     */
    public static function of(ItemAvailability $availability, array $items, ?string $syncedAt = null): self
    {
        // A list that may not be believed is not offered at all, so no caller can reach past the
        // availability flag and compare against it by accident.
        return new self($availability, $availability->isComparable() ? $items : [], $syncedAt);
    }

    /** No sync row at all: the order is mirrored but its items have never been requested. */
    public static function neverRequested(): self
    {
        return new self(ItemAvailability::NeverRequested, []);
    }

    public function isComparable(): bool
    {
        return $this->availability->isComparable();
    }

    /**
     * Lines the provider marked `deleted`, which are shown apart and never counted as missing.
     *
     * @return list<NormalizedItem>
     */
    public function removed(): array
    {
        $removed = [];

        foreach ($this->items as $item) {
            if ($item->deleted) {
                $removed[] = $item;
            }
        }

        return $removed;
    }

    /**
     * The lines a comparison pairs against — everything the provider did not mark removed.
     *
     * @return list<NormalizedItem>
     */
    public function live(): array
    {
        $live = [];

        foreach ($this->items as $item) {
            if (!$item->deleted) {
                $live[] = $item;
            }
        }

        return $live;
    }
}
