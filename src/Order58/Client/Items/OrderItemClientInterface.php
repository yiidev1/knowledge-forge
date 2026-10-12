<?php

declare(strict_types=1);

namespace App\Order58\Client\Items;

/**
 * Fetches one order's items from Order58.
 *
 * **One order per request.** The provider's endpoint takes a single `order_id`, and no bulk form is
 * documented — so this interface does not pretend one exists. A caller wanting ten orders makes ten
 * calls, which is exactly why item synchronisation is queued rather than done inside a web request.
 *
 * An interface so the service above it can be tested without a network: every test in this feature
 * substitutes a double here, which is what makes "no test reaches the live API" structural rather than
 * a promise.
 */
interface OrderItemClientInterface
{
    /**
     * The raw `OrderItems` array for one order, exactly as the provider sent it.
     *
     * Validation and mapping belong to the layer above. This returns provider shapes — strings where
     * the provider sent strings, nulls where it sent nulls — so nothing is lost before the rules get a
     * chance to look at it.
     *
     * @return array{items: list<array<array-key, mixed>>, body: string}
     *         `items` is the decoded array; `body` is the sanitized envelope to store verbatim
     *
     * @throws OrderItemDataFailed when the API is off, unconfigured, unreachable, refuses, answers
     *                             something unreadable, or answers something incomplete
     */
    public function listItems(int $accountId, int $sourceOrderId): array;
}
