<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

/**
 * What one attempt to synchronise an order's items produced.
 *
 * Five outcomes, because the page has five honest things to say — and two of them look alike and must
 * not be conflated: `Empty` means the provider answered and this order genuinely has no lines, while
 * `Failed` means we do not know. Only `Empty` is safe to compare against.
 */
final readonly class ItemSyncOutcome
{
    private function __construct(
        public ItemSyncResult $result,
        public int $itemCount,
        /** Operator-safe and already redacted; stored in `items_error`. */
        public ?string $error = null,
    ) {}

    /** Items were fetched and stored. */
    public static function synced(int $itemCount): self
    {
        return new self(ItemSyncResult::Synced, $itemCount);
    }

    /** The provider answered `OrderItems: []`. Authoritative, and comparable. */
    public static function empty(): self
    {
        return new self(ItemSyncResult::Empty, 0);
    }

    /** The response matched what is already stored; no item row was rewritten. */
    public static function unchanged(int $itemCount): self
    {
        return new self(ItemSyncResult::Unchanged, $itemCount);
    }

    /** Nothing was written and the previous snapshot, if any, was kept. */
    public static function failed(string $error): self
    {
        return new self(ItemSyncResult::Failed, 0, $error);
    }

    /** Somebody else holds this order right now. No request was made. */
    public static function busy(): self
    {
        return new self(ItemSyncResult::Busy, 0);
    }

    /**
     * The feature is off or unconfigured. Nothing was claimed and nothing was recorded.
     *
     * @param string $reason operator-safe; names settings, never values
     */
    public static function unavailable(string $reason): self
    {
        return new self(ItemSyncResult::Unavailable, 0, $reason);
    }

    /**
     * Whether the stored snapshot now reflects the provider.
     *
     * A whitelist rather than a list of exclusions: a result added later is not a success until
     * somebody says so here, which is the safer direction for a method callers use to decide whether
     * the data can be trusted.
     */
    public function isSuccess(): bool
    {
        return $this->result === ItemSyncResult::Synced
            || $this->result === ItemSyncResult::Empty
            || $this->result === ItemSyncResult::Unchanged;
    }
}
