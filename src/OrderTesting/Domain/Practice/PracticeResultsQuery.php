<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use function in_array;
use function is_string;
use function mb_substr;
use function trim;

/**
 * What the reader is asking the Practice Results table to show: a search, and an order.
 *
 * ## Why a value object rather than two parameters
 *
 * Both travel together everywhere — into the count query, into the page query, into the pager's
 * links and back out of the query string on the next request. Passing them separately is how one of
 * them gets dropped on one of those paths and a page two shows a differently filtered set from page
 * one.
 *
 * ## Everything here is validated, because all of it arrives from the address bar
 *
 * The sort is matched against an allow-list and falls back rather than erroring: a stale bookmark is
 * not a reason to show somebody an error page. The search is trimmed and bounded, and it reaches SQL
 * only as a bound parameter — never as part of a statement.
 */
final readonly class PracticeResultsQuery
{
    /** The query parameters this reads, named once so the template and the action cannot disagree. */
    public const SEARCH = 'agent';
    public const SORT = 'sort';
    public const PAGE = 'page';

    /** Longer than any username the mirror holds, and short enough to be a bounded parameter. */
    private const MAX_SEARCH = 100;

    /**
     * The orders a reader may ask for.
     *
     * `recent` first because reviewing practice is nearly always "what happened lately". The two
     * score orders are here because they are what a reviewer sorts by when they are looking for
     * somebody struggling — which is the other reason to open this page.
     *
     * @var list<string>
     */
    public const SORTS = ['recent', 'name', 'tries'];

    public const DEFAULT_SORT = 'recent';

    private function __construct(
        /** A fragment of a username, already trimmed. Null means every practitioner. */
        public ?string $search,
        /** One of {@see SORTS}. */
        public string $sort,
    ) {}

    public static function none(): self
    {
        return new self(null, self::DEFAULT_SORT);
    }

    /**
     * Read from whatever the request carried.
     *
     * @param array<array-key, mixed> $params the query string, in whatever shape it arrived
     */
    public static function fromParams(array $params): self
    {
        $search = $params[self::SEARCH] ?? null;
        $search = is_string($search) ? trim($search) : '';
        $search = $search === '' ? null : mb_substr($search, 0, self::MAX_SEARCH);

        $sort = $params[self::SORT] ?? null;
        $sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;

        return new self($search, $sort);
    }

    /** Whether anything is narrowing the list, which is what decides if Clear is offered. */
    public function isFiltered(): bool
    {
        return $this->search !== null;
    }

    /**
     * This query as query-string parameters, for the pager and the sort links.
     *
     * Defaults are omitted rather than written out, so an unfiltered page has a clean address and
     * `?sort=recent` never appears just because somebody looked at the page.
     *
     * @return array<string, string>
     */
    public function toParams(): array
    {
        $out = [];

        if ($this->search !== null) {
            $out[self::SEARCH] = $this->search;
        }

        if ($this->sort !== self::DEFAULT_SORT) {
            $out[self::SORT] = $this->sort;
        }

        return $out;
    }

    /** The same query under a different order, for the table's own sort controls. */
    public function withSort(string $sort): self
    {
        return new self($this->search, in_array($sort, self::SORTS, true) ? $sort : $this->sort);
    }
}
