<?php

declare(strict_types=1);

namespace App\Shared\Order58\Items;

use function array_merge;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sort;
use function sprintf;
use function strtolower;
use function trim;

/**
 * One order line reduced to the facets a comparison can actually ask about.
 *
 * ## Why this is shared rather than owned by either module
 *
 * An Order Items API element and a demo document's `items[]` element are **the same shape** — the same
 * `qty`, `name`, `price`, `desc`, `deleted`, `product_id` and the same `data.product` /
 * `data.additionals` / `data.combinations` nesting. The only difference is that the API sends `data` as
 * a JSON-encoded string while the listener's document has it already decoded, and
 * {@see ItemCanonicalizer} accepts both.
 *
 * So there is one canonicaliser, applied to the provider's items on the left and the demo document's on
 * the right. Giving each side its own reader would produce two parsers of one format, and the first
 * field they disagreed about would surface as a difference the trainee did not make — the same
 * reasoning that already gives the two *orders* a single normalizer.
 *
 * It lives in `App\Shared\Order58` because `src/OrderTesting/` may not name `App\Order58`, and because
 * that namespace already holds this kind of format knowledge (the payment-secret list, the demo URL).
 *
 * ## Display and matching are different strings, deliberately
 *
 * `displayName` is the provider's value byte for byte, `[F7]` and all — that prefix is a kitchen
 * routing code and the page must show what the provider actually said. `productName` is the
 * interpreted name used for matching, preferring `data.product.name` (which the demo side carries
 * un-prefixed) and falling back to stripping the prefix. Nothing ever rewrites the stored name.
 */
final readonly class CanonicalItem
{
    /**
     * @param list<array<string, mixed>> $additionals "Add / Extra" modifiers, each with its own qty
     * @param list<array<string, mixed>> $combinations chosen options of a combination product
     */
    public function __construct(
        /** The provider's `product_id`. `0` is a real value and is preserved as 0. */
        public ?int $productId,
        public ?string $productSn,
        /** Interpreted: `data.product.name`, else `displayName` with its bracketed prefix removed. */
        public ?string $productName,
        /** Lower-cased, trimmed `productName`. The fallback pairing key. */
        public string $matchKey,
        /** The provider's `name`, exactly as sent — `[F7]` included. */
        public string $displayName,
        /** DECIMAL(12,3) as a string. Never a float: `0.1 + 0.2` is how a quantity stops matching. */
        public ?string $qty,
        /** DECIMAL(15,5) as a string, for the same reason. */
        public ?string $unitPrice,
        public array $additionals,
        public array $combinations,
        public ?string $instructions,
        /** A line the provider marked removed. Reported separately, never as "missing in demo". */
        public bool $deleted,
    ) {}

    /**
     * The modifier set as one string, **order-insensitive**.
     *
     * Sorted on purpose. The two sides list modifiers in whatever order each was built in, and a
     * trainee who picked the right two extras in the other order has made no mistake — comparing the
     * document order would have reported one. Quantity and price are part of each entry, so "Extra
     * Cheese x2" and "Extra Cheese x1" remain different.
     */
    public function modifierSignature(): string
    {
        return self::signatureOf(array_merge($this->additionalParts(), $this->combinationParts()));
    }

    /** @return list<string> */
    private function additionalParts(): array
    {
        $parts = [];

        foreach ($this->additionals as $addition) {
            $parts[] = sprintf(
                'A|%s|%s|%s|%s',
                self::text($addition['name'] ?? null),
                self::text($addition['optionName'] ?? null),
                self::text($addition['qty'] ?? null),
                self::text($addition['unitPrice'] ?? null),
            );
        }

        return $parts;
    }

    /** @return list<string> */
    private function combinationParts(): array
    {
        $parts = [];

        foreach ($this->combinations as $combination) {
            $parts[] = sprintf(
                'C|%s|%s|%s',
                self::text($combination['attributeName'] ?? null),
                self::text($combination['optionName'] ?? null),
                self::text($combination['unitPrice'] ?? null),
            );
        }

        return $parts;
    }

    /**
     * One canonical string from a set of parts, sorted so order cannot matter.
     *
     * @param list<string> $parts
     */
    private static function signatureOf(array $parts): string
    {
        sort($parts);

        return implode(' + ', $parts);
    }

    /**
     * The additions as readable labels, in **document order** — for the page, not for matching.
     *
     * Kept apart from the combinations because they are different things to an operator: an addition is
     * something asked for on top of the product ("extra hot sauce"), a combination is a choice the
     * product requires ("size: large"). A page that merged them would show a difference without saying
     * which kind, and the two are fixed differently.
     *
     * @return list<string>
     */
    public function additionLabels(): array
    {
        $labels = [];

        foreach ($this->additionals as $addition) {
            $label = self::text($addition['optionName'] ?? null);
            $name = self::text($addition['name'] ?? null);
            $qty = self::text($addition['qty'] ?? null);

            $text = $label === '' ? $name : ($name === '' ? $label : $name . ': ' . $label);

            if ($text === '') {
                continue;
            }

            // Only when it is not one, so an ordinary single extra does not read as a calculation.
            $labels[] = $qty !== '' && $qty !== '1' && (float) $qty !== 1.0
                ? $text . ' x' . $qty
                : $text;
        }

        return $labels;
    }

    /**
     * The chosen combination options as readable labels, in document order.
     *
     * @return list<string>
     */
    public function combinationLabels(): array
    {
        $labels = [];

        foreach ($this->combinations as $combination) {
            $attribute = self::text($combination['attributeName'] ?? null);
            $option = self::text($combination['optionName'] ?? null);

            $text = $attribute === '' ? $option : ($option === '' ? $attribute : $attribute . ': ' . $option);

            if ($text !== '') {
                $labels[] = $text;
            }
        }

        return $labels;
    }

    /**
     * Every modifier as one readable list, in document order.
     *
     * @return list<string>
     */
    public function modifierLabels(): array
    {
        return array_merge($this->additionLabels(), $this->combinationLabels());
    }

    /**
     * The additions alone, canonicalised and order-insensitive.
     *
     * Separate from {@see combinationSignature()} so the page can say which kind of modifier differs,
     * while {@see modifierSignature()} remains the single facet the pairing cost is measured on —
     * splitting the cost into two facets would quietly double the weight of modifiers in every pairing.
     */
    public function additionSignature(): string
    {
        return self::signatureOf($this->additionalParts());
    }

    /** The chosen combination options alone, canonicalised and order-insensitive. */
    public function combinationSignature(): string
    {
        return self::signatureOf($this->combinationParts());
    }

    /**
     * The shape stored in `order58_order_items.comparison_json`.
     *
     * The key order is part of the contract: `content_hash` is computed over this, so reordering the
     * keys would make every stored row report itself changed on its next sync.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'productSn' => $this->productSn,
            'productName' => $this->productName,
            'matchKey' => $this->matchKey,
            'displayName' => $this->displayName,
            'qty' => $this->qty,
            'unitPrice' => $this->unitPrice,
            'additionals' => $this->additionals,
            'combinations' => $this->combinations,
            'instructions' => $this->instructions,
            'deleted' => $this->deleted,
        ];
    }

    /**
     * Read back from stored `comparison_json`, with no re-interpretation.
     *
     * This is what keeps the comparison page off the raw payloads: the canonical form was computed once
     * at sync time, so rendering a comparison decodes one small JSON object per line rather than
     * re-parsing a provider document.
     *
     * @param array<array-key, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $displayName = self::text($stored['displayName'] ?? null);
        $productName = self::text($stored['productName'] ?? null);

        return new self(
            productId: self::intOrNull($stored['productId'] ?? null),
            productSn: self::textOrNull($stored['productSn'] ?? null),
            productName: $productName === '' ? null : $productName,
            matchKey: self::text($stored['matchKey'] ?? null) !== ''
                ? self::text($stored['matchKey'] ?? null)
                : strtolower(trim($productName)),
            displayName: $displayName,
            qty: self::textOrNull($stored['qty'] ?? null),
            unitPrice: self::textOrNull($stored['unitPrice'] ?? null),
            additionals: self::groupOf($stored['additionals'] ?? null),
            combinations: self::groupOf($stored['combinations'] ?? null),
            instructions: self::textOrNull($stored['instructions'] ?? null),
            deleted: ($stored['deleted'] ?? false) === true,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function groupOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && $value !== '' && (string) (int) $value === $value
            ? (int) $value
            : null);
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * The facets a pairing decision is allowed to look at, as comparable strings.
     *
     * Named facets rather than a single hash so the matcher can count *how many* differ, which is what
     * lets it pair the least-wrong candidate instead of the first one it meets.
     *
     * @return array<string, string>
     */
    public function facets(): array
    {
        return [
            'qty' => (string) $this->qty,
            'unitPrice' => (string) $this->unitPrice,
            'modifiers' => $this->modifierSignature(),
            'instructions' => strtolower(trim((string) $this->instructions)),
        ];
    }
}
