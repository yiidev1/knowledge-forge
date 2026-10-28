<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\Practice\PracticeResultsReaderInterface;
use App\OrderTesting\Domain\Practice\PractitionerSummary;
use App\OrderTesting\Domain\SourceOrderReaderInterface;

use function array_reverse;
use function count;
use function min;

/**
 * Every try's score, plus best and latest, for the practitioners on one page of the table, under a
 * fixed budget.
 *
 * ## Why this is the hard part
 *
 * A score is not stored. It is derived by parsing a demo order's `raw_payload` and comparing the
 * whole document against the original — pure PHP, no query. So "best score" is not a column anybody
 * can select: it is one comparison per result that practitioner ever produced.
 *
 * That is fine at four tries and not fine at four hundred. Three things keep it bounded:
 *
 * 1. **Only the page's practitioners.** Twenty-five rows, not every practitioner in the store.
 * 2. **Latest is one comparison, always.** The reader returns results newest first, so the latest
 *    score is the first row — however many that practitioner has.
 * 3. **Best is budgeted.** See below, because this is the one that can still run away.
 *
 * ## The budget, and what it buys
 *
 * Best needs every one of that practitioner's results scored. One practitioner with two hundred
 * results would therefore cost two hundred comparisons on a page that also has twenty-four other
 * rows to draw.
 *
 * So the page has a {@see BUDGET} of comparisons to spend across all its rows. Latest is taken for
 * every row first and costs one each, which is reserved and cannot be squeezed out. Best is then
 * computed for as many rows as the remaining budget allows, in the order they are displayed.
 *
 * A row the budget could not reach does not get a wrong number or a zero — it gets **null**, and the
 * table says so and points at View Tries, where that practitioner's results are paged and scored a
 * screenful at a time. An honest blank beats a figure computed over whichever subset happened to fit.
 *
 * With ordinary data — practitioners with a handful of tries each — the budget is never approached
 * and every Best on the page is exact. It exists for the case the client asked about, not the
 * ordinary one.
 *
 * ## Every try's score comes from the same comparisons
 *
 * Scoring Best already scores each of a practitioner's results, so the row's list of try scores costs
 * nothing extra: it is those same scores, kept and numbered. The numbers come from
 * {@see PracticeResultsReaderInterface::tryNumbersFor()} — the drawer's own numbering — so Try 2 on the
 * row and Try 2 in the drawer are always the same demo order.
 */
final readonly class PractitionerScorer
{
    /**
     * Comparisons one request may spend on the table.
     *
     * 300 is roughly a megabyte of payload parsed and compared, which is the order of what the page
     * used to do unconditionally on every load. The difference is that it is now a ceiling rather
     * than a consequence: beyond it the page stays fast and says what it could not compute.
     */
    public const BUDGET = 300;

    /**
     * The most results one practitioner may contribute to Best.
     *
     * A second limit beside the budget, because the budget alone would let the first row eat all of
     * it and leave every row below with no Best at all. This way one outlier costs its own cap and
     * the rest of the page is unaffected.
     */
    public const PER_PRACTITIONER = 60;

    public function __construct(
        private PracticeResultsReaderInterface $results,
        private SourceOrderReaderInterface $sourceOrders,
        private OriginalItemsReaderInterface $originalItems,
        private DemoOrderNormalizer $normalizer,
        private OrderComparator $comparator,
        private ComparisonScorer $scorer,
    ) {}

    /**
     * Scores for each practitioner given, keyed by {@see PractitionerSummary::key()}.
     *
     * @param list<PractitionerSummary> $practitioners the page's rows, in display order
     *
     * @return array<string, PractitionerScores>
     */
    public function forPage(int $storeSourceId, int $sourceOrderId, array $practitioners): array
    {
        if ($practitioners === []) {
            return [];
        }

        // The two reads every comparison on this page shares, fetched once rather than per row.
        $original = $this->sourceOrders->find($storeSourceId, $sourceOrderId);
        $items = $this->originalItems->read($storeSourceId, $sourceOrderId);

        $out = [];
        // Latest costs one comparison per row and is reserved up front, so a greedy Best cannot
        // starve the rows below it of the figure that is always affordable.
        $budget = self::BUDGET - count($practitioners);

        foreach ($practitioners as $practitioner) {
            $cap = min($practitioner->completedTries, self::PER_PRACTITIONER);
            // Whether Best can be exact: the cap has to cover everything they produced, and the
            // remaining budget has to cover the cap.
            $exact = $cap >= $practitioner->completedTries && $budget >= $cap;

            $wanted = $exact ? $cap : 1;

            $demos = $this->results->resultsFor(
                $storeSourceId,
                $sourceOrderId,
                $practitioner->realm,
                $practitioner->actorId,
                $wanted,
                0,
            );

            $latest = null;
            $best = null;
            $tries = [];
            // Two integers a row over their whole history: what makes "Try 3" the same on every surface.
            $numbers = $demos === []
                ? []
                : $this->results->tryNumbersFor(
                    $storeSourceId,
                    $sourceOrderId,
                    $practitioner->realm,
                    $practitioner->actorId,
                );

            foreach ($demos as $i => $demo) {
                $score = $this->scorer->score(
                    $this->comparator->compare($original, $this->normalizer->normalize($demo), $items),
                );

                $tries[] = new PractitionerTry($numbers[$demo->demoOrderId] ?? 0, $demo, $score);

                // The reader returns newest first, so the first is the latest. Always exact.
                if ($i === 0) {
                    $latest = $score;
                }

                /*
                 * Only an available score can be a best one.
                 *
                 * `overall` is null when the comparison could not be made — the source order is not
                 * mirrored here, or its items were never fetched. Treating that as zero would rank a
                 * practitioner below somebody who did badly, on the strength of a comparison nobody
                 * performed.
                 */
                if ($score->isAvailable()
                    && ($best === null || (float) $score->overall > (float) $best->overall)
                ) {
                    $best = $score;
                }
            }

            if ($exact) {
                $budget -= $cap;
            }

            $out[$practitioner->key()] = new PractitionerScores(
                latest: $latest,
                // Null rather than a number computed over part of their history. A Best that silently
                // meant "best of the few we had time for" is the kind of figure somebody makes a
                // decision about a person with.
                best: $exact ? $best : null,
                bestIsExact: $exact,
                // Newest first from the reader; oldest first on the row, so the last is the latest.
                tries: array_reverse($tries),
            );
        }

        return $out;
    }
}
