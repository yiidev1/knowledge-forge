<?php

declare(strict_types=1);

namespace App\Order58\Application\Items;

use App\Order58\Domain\Items\Order58OrderItem;
use App\Order58\Domain\Items\OrderItemNotMappable;
use App\Shared\Order58\Items\ItemCanonicalizer;

use function hash;
use function is_int;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function sprintf;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * One provider item becomes one row, with every value preserved and nothing interpreted in place.
 *
 * ## Two integrations use this, and that is deliberate
 *
 * It was written for the per-order items endpoint, whose response is a list of lines for one order. The
 * Case Order endpoint sends the same lines nested inside the order instead, and
 * {@see \App\Order58\Application\CaseOrders\CaseOrderParser} hands each of them here rather than
 * mapping them itself — so `comparison_json` and `content_hash` are derived one way whichever endpoint
 * the row came from.
 *
 * That matters more than saving code. `comparison_json` is what a trainee's work is scored against, and
 * it is built by the canonicaliser the demo side also uses; a second mapping would have been a second
 * answer to "what did the customer order", and the comparison would have reported the difference
 * between the two mappings as something the trainee got wrong.
 *
 * **So this file outlives the endpoint it was written for.** It is not part of the per-order items
 * integration to be removed with it.
 *
 * ## The division of labour
 *
 * The **columns** are the provider's values, untouched: `name` keeps `[F7]`, `account_id` keeps `0`,
 * money keeps five decimals as a string, `data` stays the JSON-encoded string it arrived as.
 *
 * `comparison_json` is where interpretation happens, and only there: the prefix comes off, `data` is
 * decoded, and the product, additionals and combinations are flattened into one canonical shape. That
 * separation is what lets the comparison rules change later without re-fetching anything, and what
 * keeps the stored row a faithful copy of what the provider said.
 *
 * ## What makes an item unmappable
 *
 * Only two things, and both are identity: no usable `id`, or an `order_id` that disagrees with the
 * order we asked about. The second is the one that matters — a response describing somebody else's
 * order must never be written under this one's key, and the provider is the only party who could make
 * that mistake. Everything else degrades to null rather than refusing, because a missing `tag` is not
 * a reason to lose a line.
 */
final readonly class OrderItemMapper
{
    /** DECIMAL(15,5) / DECIMAL(12,3): what the columns will actually accept. */
    private const MONEY = '/^-?[0-9]{1,10}(\.[0-9]{1,5})?$/';
    private const QUANTITY = '/^-?[0-9]{1,9}(\.[0-9]{1,3})?$/';

    public function __construct(
        /**
         * The canonical form, shared with the comparison.
         *
         * It lives in `App\Shared\Order58` rather than here because the demo side needs the identical
         * interpretation of the identical shape, and `src/OrderTesting/` may not name this module. One
         * canonicaliser is what stops the two sides disagreeing about a name or a modifier and
         * reporting it as something the trainee did.
         */
        private ItemCanonicalizer $canonicalizer = new ItemCanonicalizer(),
    ) {}

    /**
     * @param array<array-key, mixed> $raw one element of `OrderItems`
     *
     * @throws OrderItemNotMappable when the element has no usable identity
     */
    public function toItem(int $storeAccountId, int $sourceOrderId, array $raw, int $position): Order58OrderItem
    {
        $sourceItemId = $this->id($raw['id'] ?? null);

        if ($sourceItemId === null) {
            throw new OrderItemNotMappable('an item arrived without a usable "id"');
        }

        $orderId = $this->id($raw['order_id'] ?? null);

        if ($orderId !== null && $orderId !== $sourceOrderId) {
            // The provider answered about a different order. Writing this under the requested order's
            // key would attribute one order's lines to another.
            throw new OrderItemNotMappable(sprintf(
                'item %d claims order %d, but order %d was requested',
                $sourceItemId,
                $orderId,
                $sourceOrderId,
            ));
        }

        $name = $this->text($raw['name'] ?? null, 2000) ?? '';
        $data = $this->text($raw['data'] ?? null, 4_000_000);

        $comparison = $this->comparison($raw);
        $comparisonJson = json_encode($comparison, JSON_THROW_ON_ERROR);

        return new Order58OrderItem(
            storeAccountId: $storeAccountId,
            sourceOrderId: $sourceOrderId,
            sourceItemId: $sourceItemId,
            name: $name,
            qty: $this->quantity($raw['qty'] ?? null) ?? '0',
            desc: $this->text($raw['desc'] ?? null, 65000),
            price: $this->money($raw['price'] ?? null),
            costPrice: $this->money($raw['cost_price'] ?? null),
            type: $this->text($raw['type'] ?? null, 100),
            // Preserved exactly. `0` is a real value here and must survive as 0, not become null.
            accountId: $this->id($raw['account_id'] ?? null, allowZero: true),
            userId: $this->id($raw['user_id'] ?? null, allowZero: true),
            orderId: $orderId,
            data: $data,
            customerId: $this->id($raw['customer_id'] ?? null, allowZero: true),
            productId: $this->id($raw['product_id'] ?? null, allowZero: true),
            tag: $this->text($raw['tag'] ?? null, 65000),
            packIdx: $this->id($raw['pack_idx'] ?? null, allowZero: true),
            flag: $this->text($raw['flag'] ?? null, 50),
            printIdx: $this->id($raw['print_idx'] ?? null, allowZero: true),
            deleted: $this->id($raw['deleted'] ?? null, allowZero: true),
            freeQty: $this->quantity($raw['free_qty'] ?? null),
            position: $position,
            comparisonJson: $comparisonJson,
            // Over the canonical shape AND the raw element, so a change anywhere counts — including in
            // a field this application does not yet read.
            contentHash: hash('sha256', $comparisonJson . "\0" . json_encode($raw, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * The canonical shape both sides of the comparison are read into.
     *
     * Delegated, not implemented here: {@see ItemCanonicalizer} does the same work for the demo
     * document, which has the same shape. The key order of the stored array is part of the contract —
     * `content_hash` is computed over it — and {@see \App\Shared\Order58\Items\CanonicalItem::toArray()}
     * is what fixes it.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function comparison(array $raw): array
    {
        return $this->canonicalizer->canonicalize($raw)->toArray();
    }

    /**
     * An id, as an int or a numeric string.
     *
     * `allowZero` exists because `0` is a real provider value for `account_id`, `user_id`, `deleted`
     * and `print_idx` — treating it as "absent" would silently rewrite the data this table exists to
     * preserve. For `id` and `order_id`, zero is not a usable identity.
     */
    private function id(mixed $value, bool $allowZero = false): ?int
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
    private function money(mixed $value): ?string
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
    private function quantity(mixed $value): ?string
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

    /** Trimmed, bounded to its column, null rather than empty. */
    private function text(mixed $value, int $max): ?string
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

        return substr($value, 0, $max);
    }
}
