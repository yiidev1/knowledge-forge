<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsReaderInterface;
use App\OrderTesting\Domain\SourceOrderReaderInterface;

use function max;

/**
 * One practitioner's own results for one source order, scored and numbered.
 *
 * ## What this serves
 *
 * Two readers, and they want the same thing: the drawer an administrator opens from the table, and
 * an agent's own page. Both are "show me this person's tries", both are bounded, and both need the
 * same three facts per row — which try it was, what it scored, and when.
 *
 * One service rather than two, because the second copy is where a try number starts being counted
 * from a different end on one surface than the other.
 *
 * ## Try numbers come from the database, not from the page
 *
 * Try 3 is the third result this person produced. A page showing their newest twenty cannot work
 * that out from what it holds, so the numbering is asked for over their whole set — two integers a
 * row, which costs a fraction of one document. It is therefore stable: Try 1 stays Try 1 however
 * this page is sorted, paged or filtered, and whatever they do afterwards.
 */
final readonly class PractitionerHistory
{
    /**
     * Results fetched for one view of a practitioner.
     *
     * Bounded for the same reason everything here is: an agent with two hundred results must not
     * become two hundred comparisons because somebody opened their drawer. The drawer pages.
     */
    public const PER_PAGE = 20;

    public function __construct(
        private PracticeResultsReaderInterface $results,
        private SourceOrderReaderInterface $sourceOrders,
        private OriginalItemsReaderInterface $originalItems,
        private DemoOrderNormalizer $normalizer,
        private OrderComparator $comparator,
        private ComparisonScorer $scorer,
    ) {}

    /**
     * Scores for a set of demo orders that belong to nobody.
     *
     * The unmatched section needs the same figure the attributed rows carry, from the same scorer —
     * an imported result is worth what it is worth whether or not anybody can be shown to have made
     * it. What it does NOT get is a practitioner, a try number or a place in anybody's history.
     *
     * Bounded by the caller: it is handed the rows already on screen, so the page's cost grows with
     * what it shows and not with how many unattributed results exist.
     *
     * @param list<\App\OrderTesting\Domain\DemoOrder> $demos
     *
     * @return array<int, \App\OrderTesting\Domain\Comparison\ComparisonScore> by demo order id
     */
    public function scoreEach(int $storeSourceId, int $sourceOrderId, array $demos): array
    {
        if ($demos === []) {
            return [];
        }

        $original = $this->sourceOrders->find($storeSourceId, $sourceOrderId);
        $items = $this->originalItems->read($storeSourceId, $sourceOrderId);

        $out = [];

        foreach ($demos as $demo) {
            $out[$demo->demoOrderId] = $this->scorer->score(
                $this->comparator->compare($original, $this->normalizer->normalize($demo), $items),
            );
        }

        return $out;
    }

    /**
     * One page of a practitioner's tries, newest first.
     *
     * @return list<PractitionerTry>
     */
    public function forPractitioner(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
        int $page = 1,
    ): array {
        $demos = $this->results->resultsFor(
            $storeSourceId,
            $sourceOrderId,
            $realm,
            $actorId,
            self::PER_PAGE,
            (max(1, $page) - 1) * self::PER_PAGE,
        );

        if ($demos === []) {
            return [];
        }

        // Numbered over the whole history, so the window knows where it sits in it.
        $numbers = $this->results->tryNumbersFor($storeSourceId, $sourceOrderId, $realm, $actorId);

        // The two reads every comparison in this window shares.
        $original = $this->sourceOrders->find($storeSourceId, $sourceOrderId);
        $items = $this->originalItems->read($storeSourceId, $sourceOrderId);

        $out = [];

        foreach ($demos as $demo) {
            $out[] = new PractitionerTry(
                number: $numbers[$demo->demoOrderId] ?? 0,
                demo: $demo,
                score: $this->scorer->score(
                    $this->comparator->compare($original, $this->normalizer->normalize($demo), $items),
                ),
            );
        }

        return $out;
    }
}
