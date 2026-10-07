<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

use function implode;
use function strtolower;
use function trim;

/**
 * One line of an order, in the one shape both sides are read into.
 *
 * ## No identifier from the document is used to match lines
 *
 * The client was explicit that a demo document's `OrderItem.id`, `Product.id`, `Customer.id`,
 * `Reservation.id` and `ShippingAddress.id` may be synthetic and need not correspond to anything in
 * Order58's production database. Matching on one would pair a trainee's line against an unrelated
 * original and report a difference that is really an artefact of how the demo was generated.
 *
 * So the matching key is the **product's own name**, canonicalised, with the `sn` carried alongside for
 * display. `sn` is not used as a key either, because nothing in the source proves it is stable across a
 * demo submission — if that is ever established, {@see NormalizedItem::matchKey()} is the one method
 * that changes.
 */
final readonly class NormalizedItem
{
    /** @param list<string> $options option, addition and combination labels, flattened */
    public function __construct(
        public ?string $name,
        /** The product identifier the document carries, shown but never matched on. */
        public ?string $sn,
        public ?int $quantity,
        public ?string $unitPrice,
        public ?string $total,
        public array $options = [],
        public ?string $instructions = null,
    ) {}

    /**
     * The key two lines are paired on.
     *
     * Deliberately only the name: quantity is a thing being compared, so including it would make a
     * trainee who typed the right product with the wrong count look like two separate errors — one line
     * missing and one extra — instead of one quantity difference.
     */
    public function matchKey(): string
    {
        return strtolower(trim($this->name ?? ''));
    }

    /** The options as one comparable string, in document order. */
    public function optionsLabel(): string
    {
        return implode(', ', $this->options);
    }
}
