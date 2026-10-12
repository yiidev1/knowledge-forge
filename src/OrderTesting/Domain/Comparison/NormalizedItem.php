<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

use App\Shared\Order58\Items\CanonicalItem;

use function implode;
use function strtolower;
use function trim;

/**
 * One line of an order, in the one shape both sides are read into.
 *
 * ## Display name and matching name are different strings
 *
 * `$name` is what the document said, byte for byte — `[F7]Fried Chicken Wings (4)` keeps its prefix,
 * because that bracketed code is the provider's kitchen routing and the page must show what was really
 * sent. `$productName` is the interpreted name used for pairing, which prefers `data.product.name` (the
 * un-prefixed name both sides carry) and otherwise strips the prefix. Nothing rewrites the stored value.
 *
 * ## What may be used to pair two lines, and what may not
 *
 * The client was explicit that a demo document's `OrderItem.id`, `Reservation.id` and
 * `ShippingAddress.id` may be synthetic. Those are never used.
 *
 * `product_id` is different: it is the merchant's catalogue id, and both the provider's items and the
 * demo document carry the same value for the same product. It is used **only when both sides have one**
 * — see {@see identityKey()} — and a mismatch falls back to the name rather than declaring two lines
 * unrelated. `sn` is carried for display and is never a key, because nothing establishes that it is
 * stable across a demo submission.
 */
final readonly class NormalizedItem
{
    /**
     * @param list<string> $options      every modifier label, flattened, in document order
     * @param list<string> $additions    the addition labels alone
     * @param list<string> $combinations the chosen combination labels alone
     */
    public function __construct(
        /** The document's own `name`, exactly as written. Shown, never rewritten. */
        public ?string $name,
        /** The product identifier the document carries, shown but never matched on. */
        public ?string $sn,
        public ?int $quantity,
        public ?string $unitPrice,
        public ?string $total,
        public array $options = [],
        public ?string $instructions = null,
        /** The merchant's catalogue id. Paired on only when both sides have one. */
        public ?int $productId = null,
        /** The interpreted name: no bracketed prefix. The pairing key when there is no shared id. */
        public ?string $productName = null,
        /** Quantity as the document wrote it, decimals intact — `"2.000"`, never a float. */
        public ?string $quantityText = null,
        /** The whole modifier set, canonicalised and order-insensitive. The pairing facet. */
        public ?string $modifierSignature = null,
        /** Additions alone — "extra hot sauce". Displayed and compared on its own row. */
        public array $additions = [],
        /** Chosen combination options alone — "size: large". Displayed and compared on its own row. */
        public array $combinations = [],
        /** The two above, canonicalised, so each row's verdict is order-insensitive too. */
        public ?string $additionSignature = null,
        public ?string $combinationSignature = null,
        /** A line the provider marked removed. Shown apart, never counted as missing. */
        public bool $deleted = false,
    ) {}

    /**
     * Read from the shared canonical form — the same form the demo side is read into.
     *
     * This is the only constructor the item comparison uses, which is what guarantees that a parsing
     * decision can never affect one side and not the other.
     */
    public static function fromCanonical(CanonicalItem $item, ?string $total = null): self
    {
        return new self(
            name: $item->displayName === '' ? null : $item->displayName,
            sn: $item->productSn,
            // Kept for the existing display path; `quantityText` is what the comparison reads.
            quantity: $item->qty === null ? null : (int) $item->qty,
            unitPrice: $item->unitPrice,
            // Only if the document stated one. The provider's items carry no line total, so it is
            // never computed here — an invented total compared against a real one is a false
            // difference, and it is not among the facets a comparison asks about.
            total: $total,
            options: $item->modifierLabels(),
            instructions: $item->instructions,
            productId: $item->productId,
            productName: $item->productName,
            quantityText: $item->qty,
            modifierSignature: $item->modifierSignature(),
            additions: $item->additionLabels(),
            combinations: $item->combinationLabels(),
            additionSignature: $item->additionSignature(),
            combinationSignature: $item->combinationSignature(),
            deleted: $item->deleted,
        );
    }

    /**
     * The shared catalogue identity, when the document gives one.
     *
     * `0` is the provider's "no product" value in this payload, so it is not an identity. Null means
     * "pair me by name instead".
     */
    public function identityKey(): ?string
    {
        return $this->productId === null || $this->productId === 0 ? null : 'p' . $this->productId;
    }

    /**
     * The key two lines are paired on when there is no shared catalogue id.
     *
     * Deliberately only the name: quantity is a thing being compared, so including it would make a
     * trainee who typed the right product with the wrong count look like two separate errors — one line
     * missing and one extra — instead of one quantity difference.
     */
    public function matchKey(): string
    {
        return strtolower(trim($this->productName ?? $this->name ?? ''));
    }

    /**
     * The facets a pairing decision may weigh, as comparable strings.
     *
     * Counted rather than hashed, so two lines of the same product can be paired by *how much* they
     * agree — which is what makes a basket of duplicates pair the way a person would read it.
     *
     * @return array<string, string>
     */
    public function facets(): array
    {
        return [
            'qty' => $this->quantityText ?? ($this->quantity === null ? '' : (string) $this->quantity),
            'unitPrice' => (string) $this->unitPrice,
            'modifiers' => $this->modifierSignature ?? $this->optionsLabel(),
            'instructions' => strtolower(trim((string) $this->instructions)),
        ];
    }

    /** Every modifier as one label, in document order. */
    public function optionsLabel(): string
    {
        return implode(', ', $this->options);
    }

    /** The addition labels as one string, in document order. */
    public function additionsLabel(): string
    {
        return implode(', ', $this->additions);
    }

    /** The chosen combination labels as one string, in document order. */
    public function combinationsLabel(): string
    {
        return implode(', ', $this->combinations);
    }

    /** The quantity as text for display and comparison, decimals preserved when the document gave them. */
    public function quantityLabel(): ?string
    {
        return $this->quantityText ?? ($this->quantity === null ? null : (string) $this->quantity);
    }
}
