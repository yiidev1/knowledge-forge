<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\InitiatorType;

/**
 * The read side of Practice Results: who practised one source order, and how they did.
 *
 * ## Why this exists beside the repositories it reads
 *
 * {@see \App\OrderTesting\Domain\DemoOrderRepositoryInterface::forSourceOrder()} returns every demo
 * order of a source order, each carrying its whole `raw_payload`. That is right for the importer and
 * for a page showing all of them, and wrong for a management table: a hundred practitioners with four
 * results each is four hundred documents fetched to render twenty-five rows, and the rows do not show
 * a single field from any of them.
 *
 * So this interface answers the questions the table actually asks, and answers them where the answers
 * are cheap:
 *
 * - **Who, how many, how recently** — one indexed aggregate, no payloads at all.
 * - **One practitioner's results** — bounded, and only when a reader opens that practitioner.
 *
 * The scores come later and separately; see {@see PractitionerSummary}.
 *
 * ## Everything here is scoped to one source order
 *
 * This page is one source order's history, and mixing in another order's results would make a Try
 * number meaningless. Every method takes the pair, and every query is narrowed by it.
 */
interface PracticeResultsReaderInterface
{
    /**
     * The practitioners with at least one imported, attributed result for this source order.
     *
     * Ordered by {@see PracticeResultsQuery::$sort}, then by actor so the order is total — two
     * practitioners who last practised in the same second must not swap places between pages.
     *
     * A practitioner with attempts but no imported result is **not** here. Clicking Practice Order is
     * not a result, and a table of performance that counted clicks would be measuring the wrong thing.
     *
     * @return list<PractitionerSummary>
     */
    public function practitioners(
        int $storeSourceId,
        int $sourceOrderId,
        PracticeResultsQuery $query,
        int $limit,
        int $offset,
    ): array;

    /** How many practitioners the same filter matches, for the pager. */
    public function countPractitioners(
        int $storeSourceId,
        int $sourceOrderId,
        PracticeResultsQuery $query,
    ): int;

    /**
     * One practitioner's imported results, newest first.
     *
     * Newest first because that is the order a reviewer reads them in, and because the first row is
     * the latest score — the one figure the table needs that cannot be counted. Try numbers are
     * assigned from the other end; see {@see tryNumbersFor()}.
     *
     * Bounded by `$limit`: an agent with two hundred results must not become two hundred comparisons.
     *
     * @return list<DemoOrder>
     */
    public function resultsFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
        int $limit,
        int $offset,
    ): array;

    /** How many results that practitioner has here in all, for the drawer's own pager. */
    public function countResultsFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
    ): int;

    /**
     * Try number by demo order id, for one practitioner.
     *
     * Numbered from their oldest result, so Try 1 stays Try 1 however the page is sorted, filtered or
     * paged — and whatever else that practitioner does afterwards. Computed in the database over the
     * whole set rather than from the page in hand, because a window of the newest twenty cannot know
     * how many came before it.
     *
     * @return array<int, int> demo order id => try number, 1-based
     */
    public function tryNumbersFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
    ): array;

    /** Imported results for this source order that no attempt could be shown to have produced. */
    public function unmatchedCount(int $storeSourceId, int $sourceOrderId): int;

    /** Every imported result for this source order, attributed or not. */
    public function resultCount(int $storeSourceId, int $sourceOrderId): int;

    /**
     * The unattributed results themselves, bounded.
     *
     * Their own section, never folded into a practitioner's: an order nobody can be shown to have
     * created is not anybody's to claim.
     *
     * @return list<DemoOrder>
     */
    public function unmatched(int $storeSourceId, int $sourceOrderId, int $limit, int $offset): array;
}
