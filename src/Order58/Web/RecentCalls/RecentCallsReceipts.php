<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use Yiisoft\Session\SessionInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function preg_match;

/**
 * Which receipts the Recent Calls page may open: the orders the provider's own latest-calls list just
 * returned for a store, remembered in this person's session.
 *
 * ## Why a receipt needs this at all
 *
 * The provider's receipt endpoint takes an account id and an order id and does **not** check that the
 * order is that account's — it was measured answering for one account with another account's order. So
 * whatever opens a receipt must establish ownership first. Manage Orders does it by finding the order in
 * this application's own mirror. Recent Calls lists calls the mirror usually has not reached yet, so it
 * establishes ownership the other way: the pair was in the answer the provider gave **for that store**,
 * moments ago, to this signed-in administrator. An order id typed into an address bar was never listed,
 * and is refused before any request is made.
 *
 * Only the latest list per store is kept — at most 25 ids each — so the session stays small and a
 * receipt link stays valid for as long as the list it came from is on screen.
 */
final readonly class RecentCallsReceipts
{
    private const SESSION_KEY = 'order58.recent-calls.receipts';

    public function __construct(private SessionInterface $session) {}

    /**
     * The order id a call carries, when it is one a receipt can be asked for: a positive whole number.
     */
    public static function orderIdOf(string $raw): ?int
    {
        return preg_match('/\A[1-9]\d{0,17}\z/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Replace what this store's list allows with the orders just listed.
     *
     * Writing reopens the session briefly; the provider was already answered, so nothing slow happens
     * while it is held.
     *
     * @param list<int> $orderIds
     */
    public function remember(int $accountId, array $orderIds): void
    {
        $listed = $this->listed();
        $listed[$accountId] = $orderIds;
        $this->session->set(self::SESSION_KEY, $listed);
    }

    public function allows(int $accountId, int $orderId): bool
    {
        $listed = $this->listed();

        return array_key_exists($accountId, $listed) && in_array($orderId, $listed[$accountId], true);
    }

    /** @return array<int, list<int>> */
    private function listed(): array
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if (!is_array($stored)) {
            return [];
        }

        $listed = [];

        /** @var mixed $ids */
        foreach ($stored as $accountId => $ids) {
            if (!is_int($accountId) || !is_array($ids)) {
                continue;
            }

            $listed[$accountId] = [];

            /** @var mixed $id */
            foreach ($ids as $id) {
                if (is_int($id)) {
                    $listed[$accountId][] = $id;
                }
            }
        }

        return $listed;
    }
}
