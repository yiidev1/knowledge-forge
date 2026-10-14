<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sort;

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
 * `null` in place of one of these means "no filter", which is what the audio store page passes — its
 * SQL is then exactly what it was before this existed.
 *
 * ## Two independent dimensions
 *
 * **Which topics**, which a reader chooses. Several at once, combined with OR: an order matches if it
 * carries *any* of the chosen topics. "Not assigned" is one more choice in the same list rather than a
 * mode of its own, so "Change Dish or not yet assigned" is one ordinary selection.
 *
 * "Not assigned" deliberately requires a usable order id. An upload that named no order groups under
 * its own conversation key ({@see GroupKey}) and can never carry a topic, so listing it under a choice
 * whose whole purpose is "what still needs a topic" would be noise. Those rows stay visible unfiltered.
 *
 * **Whether the order has to be real**, which a reader does not choose. Order Testing lists every
 * recording a store has, including the ones whose order id names nothing — that is a record of what was
 * uploaded and the people who can fix it need to see it. Every practice surface lists only what can
 * actually be practised, which is the same predicate that decides whether its *store* is visible at
 * all, applied one row further down.
 *
 * The two combine freely: a reader filtering by topic on a practice surface asks both at once.
 */
final readonly class OrderGroupFilter
{
    /** What the query string says for "orders with no topic", as one of the `topics[]` values. */
    public const UNASSIGNED = 'none';

    /** The query parameter the surfaces read and the pager writes back. */
    public const PARAM = 'topics';

    /**
     * How many topics one request may name.
     *
     * Not a performance limit — fifty `IN` values cost nothing — but a bound on what a crafted query
     * string can ask the database to build. The picker cannot produce more than the topics that exist.
     */
    public const MAX_TOPICS = 50;

    /**
     * @param list<int> $topicIds de-duplicated and sorted, so two spellings of one selection are one
     *                            filter and the SQL below is stable
     */
    private function __construct(
        public array $topicIds,
        public bool $includeUnassigned,
        /**
         * Keep only groups whose order exists in this store and whose audio is still held.
         *
         * Not part of the query string and not a reader's choice: it is a property of the surface
         * doing the asking.
         */
        public bool $requireLinkedOrder = false,
    ) {}

    /**
     * A filter over a chosen set of topics, or null when nothing was chosen.
     *
     * @param list<int> $topicIds
     */
    public static function forTopics(array $topicIds, bool $includeUnassigned = false): ?self
    {
        $clean = self::clean($topicIds);

        if ($clean === [] && !$includeUnassigned) {
            return null;
        }

        return new self($clean, $includeUnassigned);
    }

    /** Only practisable orders, with no topic narrowing at all. */
    public static function linkedOrdersOnly(): self
    {
        return new self([], false, true);
    }

    /**
     * The same filter, additionally restricted to practisable orders.
     *
     * Returned as a new instance rather than mutated: a filter is passed to the count and to the page
     * separately, and one of them seeing a different object than the other is the bug the whole
     * count/page contract exists to prevent.
     */
    public function withLinkedOrdersOnly(): self
    {
        return new self($this->topicIds, $this->includeUnassigned, true);
    }

    /**
     * The filter a `?topics[]=` parameter asks for, or null when it asks for nothing.
     *
     * Accepts a list or a single value, because a query string is not a form: `?topics=5` and
     * `?topics[]=5` are the same intent typed two ways. Anything unrecognised inside it — a word, a
     * negative number, a leading zero, an array nested deeper — is dropped rather than erroring, and a
     * request made entirely of unrecognised values filters nothing, which is the page the reader would
     * have got without it.
     */
    public static function fromQuery(mixed $value): ?self
    {
        /** @var list<mixed> $raw */
        $raw = is_array($value) ? array_values($value) : [$value];

        $topicIds = [];
        $unassigned = false;

        foreach (array_slice($raw, 0, self::MAX_TOPICS) as $one) {
            if (!is_string($one)) {
                continue;
            }

            if ($one === self::UNASSIGNED) {
                $unassigned = true;

                continue;
            }

            if (preg_match('/\A[1-9][0-9]{0,18}\z/', $one) === 1) {
                $topicIds[] = (int) $one;
            }
        }

        return self::forTopics($topicIds, $unassigned);
    }

    /**
     * The `topics[]` values that ask for this filter again.
     *
     * The page's own links are built from them — the pager, and the redirect after an assignment — so a
     * chosen filter survives both rather than silently resetting to every order.
     *
     * @return list<string>
     */
    public function queryValues(): array
    {
        $values = [];

        foreach ($this->topicIds as $id) {
            $values[] = (string) $id;
        }

        if ($this->includeUnassigned) {
            $values[] = self::UNASSIGNED;
        }

        return $values;
    }

    /** Is this value one of the chosen ones? Used by the picker to tick its own boxes. */
    public function hasValue(string $value): bool
    {
        return in_array($value, $this->queryValues(), true);
    }

    /** Does this filter narrow by topic at all, or is it only asking for practisable orders? */
    public function narrowsTopics(): bool
    {
        return $this->topicIds !== [] || $this->includeUnassigned;
    }

    /** How many choices the reader made, for the control's own summary. */
    public function chosenCount(): int
    {
        return count($this->queryValues());
    }

    /** A stable description, for a page title or a log line. */
    public function describe(): string
    {
        return $this->narrowsTopics() ? implode(',', $this->queryValues()) : 'all';
    }

    /**
     * @param list<int> $topicIds
     *
     * @return list<int>
     */
    private static function clean(array $topicIds): array
    {
        $positive = [];

        foreach ($topicIds as $id) {
            if ($id > 0) {
                $positive[] = $id;
            }
        }

        $unique = array_values(array_unique($positive));
        sort($unique);

        return array_slice($unique, 0, self::MAX_TOPICS);
    }
}
