<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\DemoOrderNormalizer;
use App\OrderTesting\Application\Comparison\OrderComparator;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\OrderTesting\Domain\Practice\AttemptGroup;
use App\OrderTesting\Domain\Practice\DemoOutcome;
use App\OrderTesting\Domain\Practice\PracticeTry;
use App\OrderTesting\Domain\SourceOrderReaderInterface;
use App\OrderTesting\Domain\TestAttempt;

use function count;
use function usort;

/**
 * Turns one source order's attempts and demo orders into the history a page can draw.
 *
 * ## Two queries, however many cards
 *
 * The original order and its items are the **left-hand side of every comparison on the page**, and
 * they are the same for all of them — so they are read once here and handed to the comparator for
 * each demo order. Scoring ten demo orders therefore costs ten comparisons in memory and no extra
 * round trips. Nothing is fetched per card.
 *
 * ## Attempts lead, demo orders follow
 *
 * Grouping starts from the attempts, not the demo orders, because an attempt is the thing a person
 * actually did: pressed at a known moment, as a known identity. Demo orders attach to the attempt
 * they were created during. An attempt with nothing attached is a real row — somebody practised and
 * no file has arrived — and it is shown as pending rather than hidden.
 *
 * A demo order with no attempt cannot be attributed and is never folded into somebody's group. It
 * goes to a group of its own whose actor is null, which the page renders as Unmatched.
 */
final readonly class PracticeHistoryBuilder
{
    public function __construct(
        private SourceOrderReaderInterface $sourceOrders,
        private OriginalItemsReaderInterface $originalItems,
        private DemoOrderNormalizer $normalizer,
        private OrderComparator $comparator,
        private ComparisonScorer $scorer,
    ) {}

    /**
     * @param list<TestAttempt> $attempts oldest first — the order try numbers are taken from
     * @param list<DemoOrder>   $demoOrders every demo order of this source order
     *
     * @return list<AttemptGroup>
     */
    public function build(
        int $storeSourceId,
        int $sourceOrderId,
        array $attempts,
        array $demoOrders,
        bool $includeUnmatched,
    ): array {
        // The two reads that serve every card on the page.
        $original = $this->sourceOrders->find($storeSourceId, $sourceOrderId);
        $items = $this->originalItems->read($storeSourceId, $sourceOrderId);

        /** @var array<int, list<DemoOutcome>> $byAttempt */
        $byAttempt = [];
        /** @var list<DemoOutcome> $orphans */
        $orphans = [];

        foreach ($demoOrders as $demo) {
            $outcome = new DemoOutcome(
                $demo,
                $this->scorer->score(
                    $this->comparator->compare($original, $this->normalizer->normalize($demo), $items),
                ),
            );

            if ($demo->matchedAttemptId === null) {
                $orphans[] = $outcome;

                continue;
            }

            $byAttempt[$demo->matchedAttemptId][] = $outcome;
        }

        /** @var array<string, array{type: InitiatorType, id: int, name: ?string, tries: list<PracticeTry>}> $groups */
        $groups = [];

        foreach ($attempts as $attempt) {
            $key = $attempt->initiatedByType->value . ':' . (string) $attempt->initiatedById;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'type' => $attempt->initiatedByType,
                    'id' => $attempt->initiatedById,
                    'name' => $attempt->initiatedByName,
                    'tries' => [],
                ];
            }

            $outcomes = $byAttempt[$attempt->id] ?? [];

            /*
             * An attempt with nothing imported is not a try.
             *
             * Pressing Practice Order records that somebody intended to practise; it does not record
             * that they did. A reader who opened Order58 and closed it, or clicked twice, or is still
             * working, would otherwise fill the page with cards saying nothing happened — six of them
             * around one real result, on the live store.
             *
             * The attempt row is NOT discarded: it stays in `order_testing_attempts`, where
             * attribution, auditing and concurrency need it, and it is what a late import attaches
             * itself to. Only the card is withheld, and only until there is something to show.
             */
            if ($outcomes === []) {
                continue;
            }

            /*
             * Numbered from the results, within this person's own group.
             *
             * Taking the number from the attempt's position would leave gaps — Try 1, Try 4, Try 7 —
             * which reads as missing history rather than as unproductive clicks. Counting the cards
             * instead gives 1, 2, 3.
             *
             * It stays stable, because the attempts are ordered oldest-first and a demo order
             * attaches to the attempt it was created during: a file imported late slots into its own
             * place in that order rather than onto the end. Numbers only ever appear, never move.
             */
            $groups[$key]['tries'][] = new PracticeTry(
                count($groups[$key]['tries']) + 1,
                $attempt,
                $outcomes,
            );
        }

        // A practitioner whose every attempt is still unproductive has no cards, and therefore no
        // heading either — an empty group would be a name with nothing under it.
        foreach ($groups as $key => $group) {
            if ($group['tries'] === []) {
                unset($groups[$key]);
            }
        }

        $out = [];

        foreach ($groups as $group) {
            $out[] = new AttemptGroup($group['type'], $group['id'], $group['name'], $group['tries']);
        }

        // Most recently active first, so the person who just practised is at the top. Compared on the
        // last try's start, which is the only thing that moves as people keep practising.
        usort($out, static function (AttemptGroup $a, AttemptGroup $b): int {
            $left = $a->tries === [] ? null : $a->tries[count($a->tries) - 1]->attempt?->startedAt;
            $right = $b->tries === [] ? null : $b->tries[count($b->tries) - 1]->attempt?->startedAt;

            return ($right?->getTimestamp() ?? 0) <=> ($left?->getTimestamp() ?? 0);
        });

        /*
         * Unmatched last, and only where the reader is allowed to see it.
         *
         * An agent is shown their own attributed history; a demo order nobody can be shown to have
         * made is not theirs to claim, so it is not offered to them at all. Administrators and
         * operations users see it, because finding out what happened to it is their job.
         */
        if ($includeUnmatched && $orphans !== []) {
            $tries = [];
            $number = 1;

            foreach ($orphans as $orphan) {
                // Each unmatched demo order stands alone: they share no attempt, so calling them
                // "tries" of one thing would invent a relationship the evidence does not support.
                $tries[] = new PracticeTry($number++, null, [$orphan]);
            }

            $out[] = new AttemptGroup(null, null, null, $tries);
        }

        return $out;
    }
}
