<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function preg_match;

/**
 * Which of a store's orders a page is asking for.
 *
 * ## Why this is a parameter of the query and not a filter applied afterwards
 *
 * The store page pages by group, fifteen orders at a time. Narrowing the page *after* it has been
 * fetched would hand the reader three rows on a page that claims fifteen, and a pager counting orders
 * the filter had already removed. So the filter travels into
 * {@see StoreOrderGroupRepositoryInterface::pageFor()} and
 * {@see StoreOrderGroupRepositoryInterface::countFor()} together, and both answer the same question.
 *
 * `null` in place of one of these means "no filter", which is what Order Testing and the audio store
 * page pass — their SQL is then exactly what it was before this existed.
 *
 * ## The two things it can ask for
 *
 * - **a topic**: the orders being practised under it;
 * - **unassigned**: orders that carry no topic yet.
 *
 * "Unassigned" deliberately requires a usable order id. An upload that named no order groups under its
 * own conversation key ({@see GroupKey}) and can never carry a topic, so listing it under a filter whose
 * whole purpose is "what still needs a topic" would be noise. Those rows stay visible unfiltered.
 */
final readonly class OrderGroupFilter
{
    /** What the query string says for "every order", alongside a missing parameter. */
    public const ALL = '';

    /** What the query string says for "orders with no topic". */
    public const UNASSIGNED = 'none';

    private function __construct(
        public ?int $topicId,
        public bool $onlyUnassigned,
    ) {}

    public static function forTopic(int $topicId): self
    {
        return new self($topicId, false);
    }

    public static function unassigned(): self
    {
        return new self(null, true);
    }

    /**
     * The filter a `?topic=` parameter asks for, or null when it asks for nothing.
     *
     * Anything unrecognised — a word, a negative number, an empty string, a missing parameter — filters
     * nothing rather than erroring. A query string is not a form: a reader who edits one by hand, or
     * follows a stale link, gets the unfiltered page they would have got without it, which is the
     * behaviour every other optional parameter on this page already has.
     */
    public static function fromQuery(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        if ($value === self::UNASSIGNED) {
            return self::unassigned();
        }

        return preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            ? self::forTopic((int) $value)
            : null;
    }

    /**
     * The `?topic=` value that asks for this filter again.
     *
     * The page's own links are built from it — the pager, and the redirect after a topic is set — so a
     * chosen filter survives both rather than silently resetting to every order.
     */
    public function queryValue(): string
    {
        return $this->onlyUnassigned ? self::UNASSIGNED : (string) $this->topicId;
    }
}
