<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

/**
 * One line of an original Order58 order, mapped but not interpreted.
 *
 * ## Two identities, and they are not the same thing
 *
 * `storeAccountId` and `sourceOrderId` come from the **request** — the store and order we asked about —
 * and are what every query scopes by. `accountId`, `userId` and `orderId` are the provider's own
 * fields, preserved exactly as sent: in the client's samples `account_id` and `user_id` are both `"0"`,
 * which is precisely why they cannot be used for scoping. The same rule the orders mirror already
 * follows, for the same reason: *"A response that disagreed with the account we asked about would
 * otherwise file one store's order under another's."*
 *
 * ## Money and quantities stay strings
 *
 * The provider sends `"11.35000"` and `"1"`. They travel as strings from the wire to the DECIMAL
 * column, because a float turns five decimals into something that no longer compares equal to the demo
 * side — and comparing the two sides is the entire point of storing this.
 *
 * ## `name` keeps its prefix, `data` keeps its quotes
 *
 * `name` is `"[F7]Fried Chicken Wings (4)"` exactly. `data` is the provider's JSON-encoded **string**,
 * stored as a string. Stripping the prefix and decoding `data` happen only when building
 * `comparisonJson`, so the stored row stays a faithful copy of what arrived.
 */
final readonly class Order58OrderItem
{
    public function __construct(
        // Verified identity, from the request.
        public int $storeAccountId,
        public int $sourceOrderId,
        public int $sourceItemId,
        // The provider's own fields.
        public string $name,
        public string $qty,
        public ?string $desc,
        public ?string $price,
        public ?string $costPrice,
        public ?string $type,
        public ?int $accountId,
        public ?int $userId,
        public ?int $orderId,
        public ?string $data,
        public ?int $customerId,
        public ?int $productId,
        public ?string $tag,
        public ?int $packIdx,
        public ?string $flag,
        public ?int $printIdx,
        public ?int $deleted,
        public ?string $freeQty,
        // Derived.
        public int $position,
        public string $comparisonJson,
        public string $contentHash,
    ) {}
}
