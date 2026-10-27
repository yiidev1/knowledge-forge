<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * Everything one order said about its calls, ready to be compared with what is stored.
 *
 * Built from a Case Order as it is synchronised, or from `payload_json` by the backfill — the same shape
 * either way, so there is one comparison and one set of rules for both.
 */
final readonly class OrderCalls
{
    /**
     * @param list<ListedCall> $calls         empty when the order carried no usable `calls`
     * @param int              $rejectedLinks links dropped because they were not recording URLs
     */
    public function __construct(
        public int $storeSourceId,
        public int $sourceOrderId,
        public array $calls,
        public int $rejectedLinks = 0,
    ) {}

    /** The `(store, order)` pair as one string, the way every batched lookup here is keyed. */
    public function key(): string
    {
        return self::keyOf($this->storeSourceId, $this->sourceOrderId);
    }

    public static function keyOf(int $storeSourceId, int $sourceOrderId): string
    {
        return $storeSourceId . ':' . $sourceOrderId;
    }

    /** Whether any listed session actually names a file. */
    public function hasLinks(): bool
    {
        foreach ($this->calls as $call) {
            if ($call->urls !== []) {
                return true;
            }
        }

        return false;
    }
}
