<?php

declare(strict_types=1);

namespace App\Shared\Order58\Items;

use JsonException;

use function array_slice;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function ltrim;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * One raw order-line element becomes one {@see CanonicalItem}.
 *
 * ## The single place a provider value is reinterpreted
 *
 * The stored row keeps the provider's values untouched — `name` with its `[F7]`, money with five
 * decimals as a string, `data` as the string it arrived as. Everything interpretive happens here and
 * only here: the prefix comes off, `data` is decoded, and the product, additionals and combinations are
 * flattened into one shape. That separation is what lets the comparison rules change without
 * re-fetching anything.
 *
 * ## `data` is a string on one side and an object on the other
 *
 * The Order Items API sends `data` as a JSON-encoded **string**. The listener's demo document has the
 * same block already decoded as an **object**. Both are accepted, because neither side promises which
 * and refusing one would mean a second parser for the same format.
 *
 * ## Why unreadable modifiers are not fatal
 *
 * A `data` block that will not decode yields no modifiers rather than refusing the line. The stored row
 * still holds `data` verbatim, so nothing is lost and a later fix can re-derive from it; dropping the
 * line would lose a product the customer actually ordered over a malformed sub-document.
 */
final readonly class ItemCanonicalizer
{
    /** DECIMAL(15,5) / DECIMAL(12,3): what the columns will actually accept. */
    private const MONEY = '/^-?[0-9]{1,10}(\.[0-9]{1,5})?$/';
    private const QUANTITY = '/^-?[0-9]{1,9}(\.[0-9]{1,3})?$/';

    /** `[F7]Fried Chicken Wings (4)` — the bracketed routing code the provider prefixes to `name`. */
    private const NAME_PREFIX = '/^\[[^\]]{1,16}\]\s*/u';

    private const MAX_OPTIONS = 50;

    /**
     * @param array<array-key, mixed> $raw one element of `OrderItems`, or of a demo document's `items`
     */
    public function canonicalize(array $raw): CanonicalItem
    {
        $displayName = self::text($raw['name'] ?? $raw['product_name'] ?? $raw['title'] ?? null, 2000) ?? '';

        $decoded = self::decodeData($raw['data'] ?? null);
        $product = self::nested($decoded['product'] ?? null);

        // `data.product.name` is the un-prefixed name, and it is the one the demo side carries too.
        // Stripping the prefix off the display name is the fallback, not the first choice.
        $productName = self::text($product['name'] ?? null, 255) ?? self::stripPrefix($displayName);

        return new CanonicalItem(
            productId: self::id($raw['product_id'] ?? null, allowZero: true),
            productSn: self::text($product['sn'] ?? $raw['sn'] ?? $raw['product_sn'] ?? null, 32),
            productName: $productName,
            matchKey: strtolower(trim($productName)),
            displayName: $displayName,
            qty: self::quantity($raw['qty'] ?? $raw['quantity'] ?? null),
            unitPrice: self::money($raw['price'] ?? $raw['unit_price'] ?? null),
            additionals: self::additionals($decoded, $raw),
            combinations: self::combinations($decoded),
            instructions: self::text($raw['desc'] ?? $raw['instruction'] ?? $raw['note'] ?? null, 500),
            deleted: self::id($raw['deleted'] ?? null, allowZero: true) === 1,
        );
    }

    /**
     * `additionals` — the "Add / Extra" style modifiers, each with its own quantity and price.
     *
     * Top-level `options` / `additions` / `modifiers` are folded in as plain labels. The provider does
     * not use those keys — they appear in simpler documents written against this format, and reading
     * them here is what keeps one canonicaliser rather than a second reader for the simpler shape.
     *
     * @param array<array-key, mixed> $decoded
     * @param array<array-key, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    private static function additionals(array $decoded, array $raw = []): array
    {
        $out = [];

        /** @var mixed $group */
        $group = $decoded['additionals'] ?? null;

        // `null` is the common case and means "none", not "unreadable".
        if (is_array($group)) {
            foreach (array_slice($group, 0, self::MAX_OPTIONS) as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $out[] = [
                    'name' => self::text($option['additional_name'] ?? null, 190),
                    'optionName' => self::text($option['option_name'] ?? null, 190),
                    'qty' => self::quantity($option['qty'] ?? null),
                    'unitPrice' => self::money($option['price'] ?? null),
                    'target' => self::text($option['target'] ?? null, 190),
                ];
            }
        }

        foreach (['options', 'additions', 'modifiers'] as $key) {
            /** @var mixed $plain */
            $plain = $raw[$key] ?? null;

            if (!is_array($plain)) {
                continue;
            }

            foreach (array_slice($plain, 0, self::MAX_OPTIONS) as $option) {
                $label = is_array($option)
                    ? self::text($option['name'] ?? $option['label'] ?? $option['title'] ?? null, 190)
                    : self::text($option, 190);

                if ($label === null) {
                    continue;
                }

                $out[] = [
                    'name' => null,
                    'optionName' => $label,
                    'qty' => null,
                    'unitPrice' => null,
                    'target' => null,
                ];
            }
        }

        return $out;
    }

    /**
     * `combinations` — an object with an `options` list, not a list itself.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return list<array<string, mixed>>
     */
    private static function combinations(array $decoded): array
    {
        $block = self::nested($decoded['combinations'] ?? null);
        /** @var mixed $options */
        $options = $block['options'] ?? null;

        if (!is_array($options)) {
            return [];
        }

        $out = [];

        foreach (array_slice($options, 0, self::MAX_OPTIONS) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $out[] = [
                'attributeName' => self::text($option['attribute_name'] ?? null, 190),
                'optionName' => self::text($option['option_name'] ?? null, 190),
                'unitPrice' => self::money($option['price'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The `data` block, whichever way it arrived.
     *
     * @return array<array-key, mixed>
     */
    private static function decodeData(mixed $data): array
    {
        // The demo document's block is already an object. Nothing to decode.
        if (is_array($data)) {
            return $data;
        }

        if (!is_string($data) || trim($data) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<array-key, mixed> */
    private static function nested(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** `[F7]Fried Chicken Wings (4)` becomes `Fried Chicken Wings (4)`. */
    private static function stripPrefix(string $name): string
    {
        return trim((string) preg_replace(self::NAME_PREFIX, '', $name));
    }

    /**
     * An id, as an int or a numeric string.
     *
     * `allowZero` exists because `0` is a real provider value for `product_id`, `deleted` and friends —
     * treating it as "absent" would rewrite the data this shape exists to preserve.
     */
    private static function id(mixed $value, bool $allowZero = false): ?int
    {
        $int = null;

        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?[0-9]{1,19}$/', trim($value)) === 1) {
            $int = (int) ltrim(trim($value), '+');
        }

        if ($int === null) {
            return null;
        }

        return $allowZero || $int > 0 ? $int : null;
    }

    /** Money as a string, five decimals preserved. Never a float. */
    private static function money(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::MONEY, $value) === 1 ? $value : null;
    }

    /** Quantity as a string, three decimals, for the same reason money is. */
    private static function quantity(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::QUANTITY, $value) === 1 ? $value : null;
    }

    /** Trimmed, bounded, null rather than empty. */
    private static function text(mixed $value, int $max): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
