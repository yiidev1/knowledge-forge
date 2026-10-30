<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\NormalizedAddress;
use App\OrderTesting\Domain\Comparison\NormalizedCustomer;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\NormalizedTotals;
use App\Shared\Order58\Items\ItemCanonicalizer;
use App\Shared\Order58\PaymentSecretRedactor;
use DateTimeZone;
use JsonException;

use function array_slice;
use function in_array;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function preg_match;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * The single reader for an Order58 order document, whichever side it came from.
 *
 * One class reads `order58_orders.payload_json` and `order_testing_demo_orders.raw_payload`, because
 * they hold the same kind of object — see {@see NormalizedOrder}. It is also what the importer uses to
 * fill the demo table's columns, so the values stored and the values compared come from the same code
 * and can never disagree.
 *
 * ## It reads alternatives, and it never invents
 *
 * Real documents spell the same thing more than one way — `name` or `product_name`, `zipcode` or
 * `postal_code`, `subtotal` or `seawolf_subtotal`. Each is tried in a fixed order and the first present
 * value wins. Nothing is derived: a missing subtotal stays null rather than becoming total minus tax,
 * because a computed value on one side and a recorded one on the other is a difference the trainee did
 * not make.
 *
 * ## Every string is bounded
 *
 * The documents are a third party's. Lengths are cut to what the columns and the page can hold, and the
 * item list is capped, so one malformed file cannot refuse a row or render a page nobody can close.
 */
final readonly class OrderNormalizer
{
    /** DECIMAL(12,5): seven integer digits and five decimals, which is what the columns accept. */
    private const MONEY = '/^-?[0-9]{1,7}(\.[0-9]{1,5})?$/';

    private const MAX_ITEMS = 200;

    public function __construct(
        /**
         * The line-item canonicaliser, shared with the Order58 item sync.
         *
         * One canonicaliser for both sides: a demo document's `items[]` element has the same shape as
         * an Order Items API element, so reading them differently is how a parsing decision becomes a
         * difference the trainee did not make. Defaulted so the many existing `new OrderNormalizer()`
         * call sites keep working.
         */
        private ItemCanonicalizer $canonicalizer = new ItemCanonicalizer(),
    ) {}

    /** @param array<array-key, mixed> $raw a decoded order document */
    public function normalize(array $raw): NormalizedOrder
    {
        $data = self::nested($raw['data'] ?? null);
        // Through the same helper, so each block is a typed array whether the provider sent it as an
        // object or as a JSON-encoded string — both shapes occur, in the same document.
        $reservation = self::nested($data['reservation'] ?? null);
        $shipping = self::nested($data['shipping'] ?? null);
        $commit = self::nested($raw['commit_data'] ?? null);

        // The customer's NAME alone falls back through the document; everything else below still comes
        // from `reservation`, because that is where this provider keeps it and widening the others
        // would change fields nobody asked about.
        $named = self::firstNamedBlock(
            $reservation,
            $shipping,
            self::nested($commit['reservation'] ?? null),
            self::nested($commit['shipping'] ?? null),
        );

        return new NormalizedOrder(
            orderId: self::intOf($raw['id'] ?? null),
            customer: new NormalizedCustomer(
                firstName: self::stringOf($named['first_name'] ?? null, 128),
                lastName: self::stringOf($named['last_name'] ?? null, 128),
                phone: self::phone($reservation['phone'] ?? null),
                email: self::stringOf($reservation['email'] ?? null, 190),
                customerCount: self::intOf($reservation['customer_count'] ?? $reservation['people'] ?? null),
                instructions: self::stringOf(
                    $reservation['instruction'] ?? $reservation['instructions'] ?? $raw['instruction'] ?? null,
                    500,
                ),
            ),
            orderType: self::stringOf($raw['type'] ?? null, 32),
            paymentMethod: self::stringOf($raw['payment_method'] ?? null, 32),
            status: self::stringOf($raw['status'] ?? null, 32),
            address: new NormalizedAddress(
                street: self::stringOf($shipping['street1'] ?? $shipping['address'] ?? null, 255),
                street2: self::stringOf($shipping['street2'] ?? null, 255),
                city: self::stringOf($shipping['city'] ?? null, 128),
                state: self::stringOf($shipping['state'] ?? null, 64),
                postalCode: self::stringOf($shipping['zipcode'] ?? $shipping['postal_code'] ?? null, 32),
                destination: self::stringOf(
                    $shipping['destination_address'] ?? $shipping['destination'] ?? null,
                    255,
                ),
            ),
            totals: new NormalizedTotals(
                subtotal: self::money($raw['subtotal'] ?? $raw['seawolf_subtotal'] ?? null),
                shippingFee: self::money($raw['shipping_fee'] ?? null),
                tax: self::money($raw['tax'] ?? null),
                surcharge: self::money($raw['surcharge'] ?? null),
                tip: self::money($raw['tip'] ?? null),
                discount: self::money($raw['discount'] ?? null),
                total: self::money($raw['total_amount'] ?? $raw['total_cost'] ?? null),
                processingFee: self::money($raw['cc_processing_fee'] ?? null),
            ),
            items: $this->items($raw, $data),
            createdAt: self::timestamp($raw['created_at'] ?? null),
            updatedAt: self::timestamp($raw['updated_at'] ?? null),
            // Only the digits a stored order kept, and only when they are exactly four. The card
            // reference itself never reaches this class: it is removed before anything is stored.
            cardLastFour: self::cardLastFour($raw[PaymentSecretRedactor::CARD_LAST_FOUR] ?? null),
            timezone: self::timezone($raw['timezone'] ?? null),
        );
    }

    /** Four digits, or nothing. */
    private static function cardLastFour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}$/', $value) === 1 ? $value : null;
    }

    /** A time zone this server knows by that exact name, or nothing — never a guess at one. */
    private static function timezone(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : null;
    }

    /** Decode a stored payload and normalize it. Null when it is not a usable document. */
    public function fromJson(?string $payloadJson): ?NormalizedOrder
    {
        if ($payloadJson === null || trim($payloadJson) === '') {
            return null;
        }

        try {
            /** @var mixed $raw */
            $raw = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($raw) ? $this->normalize($raw) : null;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param array<array-key, mixed> $data
     *
     * @return list<NormalizedItem>
     */
    private function items(array $raw, array $data): array
    {
        /** @var mixed $lines */
        $lines = $raw['items'] ?? $data['items'] ?? null;

        if (!is_array($lines)) {
            return [];
        }

        $items = [];

        foreach (array_slice($lines, 0, self::MAX_ITEMS) as $line) {
            if (!is_array($line)) {
                continue;
            }

            // Through the shared canonicaliser, so the product name, modifier set and instructions are
            // derived exactly as they are for the original's items. `total` is the one value taken
            // straight from the document: the provider states no line total, so there is nothing to
            // canonicalise and nothing to compare it against.
            $items[] = NormalizedItem::fromCanonical(
                $this->canonicalizer->canonicalize($line),
                self::money($line['total'] ?? $line['total_price'] ?? null),
            );
        }

        return $items;
    }

    /**
     * `data` and `commit_data` arrive as JSON-encoded **strings**, not objects.
     *
     * An already-decoded array is accepted too, because the provider does not promise which — the same
     * allowance the live orders mirror makes.
     *
     * @return array<array-key, mixed>
     */
    /**
     * The first block that actually names somebody, in the order the caller listed them.
     *
     * ## Why a block is chosen rather than each field separately
     *
     * Taking the first non-empty `first_name` from one block and the first non-empty `last_name` from
     * another would invent a person who appears nowhere in the document — a reservation's forename
     * welded to a delivery recipient's surname. So one block wins outright and both names come from it.
     *
     * A block counts as naming somebody when the two parts, trimmed, are not both empty: a document
     * carrying only a forename is still a name, and the spec asks for the parts that exist. Non-scalar
     * values are ignored rather than rendered, so an array where a name belongs cannot reach a page.
     *
     * Returns `[]` when no block qualifies, which leaves both names null and the page showing `-`. That
     * is the correct answer for 390 of this installation's 396 mirrored orders, and it is deliberately
     * not softened into a customer id or any other identifier.
     *
     * @param array<array-key, mixed> ...$blocks
     *
     * @return array<array-key, mixed>
     */
    private static function firstNamedBlock(array ...$blocks): array
    {
        foreach ($blocks as $block) {
            if (self::namePart($block['first_name'] ?? null) . self::namePart($block['last_name'] ?? null) !== '') {
                return $block;
            }
        }

        return [];
    }

    /** A name part is a trimmed scalar, or nothing at all. */
    private static function namePart(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function nested(mixed $value): array
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return [];
            }

            try {
                /** @var mixed $value */
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        return is_array($value) ? $value : [];
    }

    /** A clean integer literal, as an int or a string. `"1.5"` and `"1abc"` are not integers. */
    public static function intOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?[0-9]{1,19}$/', trim($value)) === 1
            ? (int) trim($value)
            : null;
    }

    /** Kept a string throughout: five-decimal money, never a float. */
    public static function money(mixed $value): ?string
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

    /** Unix seconds. Zero is "not set", not 1970. */
    public static function timestamp(mixed $value): ?int
    {
        $seconds = self::intOf($value);

        return $seconds !== null && $seconds > 0 ? $seconds : null;
    }

    /**
     * A phone as recorded — never reformatted, never cast.
     *
     * A leading `0` or `+` is part of the number and casting would destroy it. The same rule the live
     * orders mirror applies.
     */
    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\+?[0-9][0-9 .()-]{2,30}$/', $value) === 1 ? $value : null;
    }

    /** Trimmed, bounded to its column, and null rather than empty. */
    public static function stringOf(mixed $value, int $max): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Cut to the column rather than letting the driver refuse the whole row: one over-long product
        // name must not lose an entire demo order.
        return substr($value, 0, $max);
    }
}
