<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * What makes several uploads one row on a store's page.
 *
 * An administrator thinks in **calls**, not uploads: the mixed recording, the caller's side and the
 * callee's side of order 16513791 are one thing that happened, however many times somebody pressed
 * Upload. The order id is what ties them together — when there is one.
 *
 * ## Why a value object rather than a string built at each call site
 *
 * The same key is computed in SQL (to count and to page) and in PHP (to read the page back, and to
 * answer the two modal endpoints). If those two ever disagreed about the format, the page would
 * silently show one row while the modal behind it resolved another — the sort of mismatch that reads
 * as "the transcript is wrong" rather than as a bug. So the format is written down once, here, and
 * {@see sqlExpression()} is the *only* place SQL learns it.
 *
 * ## The two namespaces
 *
 * ```
 * order:16513791                                    an order, however many uploads it has
 * conversation:2f6c…                                one upload that named no order
 * ```
 *
 * Most rows in this database have no order id, and they must never collapse into one another: two
 * unrelated recordings uploaded on different days are two rows, not one row called "no order". The
 * fallback therefore uses the conversation's own public id, which is unique by definition.
 *
 * The two namespaces cannot collide: {@see OrderId} accepts digits only, so an order key is always
 * `order:` followed by digits and can never be mistaken for `conversation:` followed by 32 hex.
 */
final readonly class GroupKey
{
    private const ORDER_PREFIX = 'order:';
    private const CONVERSATION_PREFIX = 'conversation:';

    private function __construct(public string $value) {}

    public static function forOrder(string $orderId): self
    {
        return new self(self::ORDER_PREFIX . $orderId);
    }

    public static function forConversation(string $publicId): self
    {
        return new self(self::CONVERSATION_PREFIX . $publicId);
    }

    /**
     * The key a conversation belongs to: its order when it named one, itself otherwise.
     */
    public static function of(AudioConversation $conversation): self
    {
        return $conversation->orderId === null
            ? self::forConversation($conversation->publicId)
            : self::forOrder($conversation->orderId);
    }

    /**
     * Whatever arrived in a URL, or null when it is not a key this application could ever have issued.
     *
     * Shape-checked rather than trusted: the value reaches a query, and refusing an impossible one here
     * means the repository never has to wonder. It is **not** an authorization check — that is the
     * store predicate in the query itself, which is what stops one store's key resolving under another.
     */
    public static function fromInput(string $raw): ?self
    {
        if (preg_match('/\Aorder:\d{1,20}\z/', $raw) === 1) {
            return new self($raw);
        }

        return preg_match('/\Aconversation:[0-9a-f]{32}\z/', $raw) === 1 ? new self($raw) : null;
    }

    /**
     * The SQL that produces this key, for the one store page query that groups by it.
     *
     * `CASE` rather than `COALESCE(order_id, …)`: coalescing would emit a bare `16513791` for an order
     * and a prefixed `conversation:…` for everything else, so SQL and PHP would be describing the same
     * row differently. Every query that groups, counts or filters by key uses this method.
     *
     * @param string $alias the table alias the columns are addressed by
     */
    public static function sqlExpression(string $alias = 'c'): string
    {
        return sprintf(
            "CASE WHEN %1\$s.order_id IS NOT NULL THEN CONCAT('%2\$s', %1\$s.order_id) "
                . "ELSE CONCAT('%3\$s', %1\$s.public_id) END",
            $alias,
            self::ORDER_PREFIX,
            self::CONVERSATION_PREFIX,
        );
    }

    /** The order id this key names, or null when it names a single conversation instead. */
    public function orderId(): ?string
    {
        return str_starts_with($this->value, self::ORDER_PREFIX)
            ? substr($this->value, strlen(self::ORDER_PREFIX))
            : null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
