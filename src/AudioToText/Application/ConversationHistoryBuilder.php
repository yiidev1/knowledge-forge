<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevision;
use App\AudioToText\Domain\Speaker\ConversationDiff;
use App\AudioToText\Domain\Speaker\HistoryEvent;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\TurnLineage;

use function array_slice;
use function array_values;
use function count;
use function krsort;

/**
 * The correction history of each message of the conversation as it stands now.
 *
 * ## Where the history comes from
 *
 * `audio_segment_revisions` stores, per correction, the whole conversation *before* it. So revision N's
 * snapshot is also the state that revision N−1 produced, and the last revision's result is the
 * conversation on the job. That gives an unbroken chain of states — verified: `review_count` equals the
 * revision count on every corrected job in this installation, so no operation writes without recording
 * one.
 *
 * Walking that chain and diffing each consecutive pair ({@see ConversationDiff}) yields, for every
 * correction, exactly which run of messages it replaced. Lineage is then carried forward: untouched
 * messages keep theirs, produced messages inherit the event plus whatever the messages they replaced had
 * already collected.
 *
 * ## What is deliberately not claimed
 *
 * Where a run replaced two messages with two others — moving a selection between neighbours — both
 * results share one event naming all four messages. The stored data cannot show which result descends
 * from which source, so nothing here pretends it can. The same event appearing on both is the honest
 * rendering; inventing a one-to-one ancestry would be a guess wearing the clothes of a record.
 *
 * ## Two operations that are not message history
 *
 * **Confirming roles** changes no message — its diff is empty on all 23 confirmations recorded here — so
 * it produces no event. It is a statement about the conversation, and the page already reports it as one.
 *
 * **Reverting** discards the reviewed layer entirely, putting the machine's own conversation back. The
 * messages that follow are new; corrections made before it describe a conversation that no longer
 * exists. So a revert resets the baseline instead of contributing an event, and a job whose last
 * correction was a revert has no message history at all — which is the truth about it.
 */
final readonly class ConversationHistoryBuilder
{
    /**
     * A ceiling on how much of the conversation one correction may be said to have touched.
     *
     * Every operation today changes a run of at most three messages, and this exists for the one that
     * might not: rather than attribute a sprawling change to individual messages on the strength of an
     * assumption that had stopped holding, the event is dropped. A missing history entry is a gap; a
     * wrong one is a lie, and only the second is worth guarding against.
     */
    private const MAX_ATTRIBUTABLE_RUN = 8;

    /**
     * @param list<SegmentRevision> $revisions oldest first, as the repository returns them
     *
     * @return list<TurnLineage> one per turn of `$current`, in the same order
     */
    public function build(array $revisions, ReviewedConversationTurns $current): array
    {
        $states = $this->statesFrom($revisions, $current);

        if ($states === null) {
            // An unreadable snapshot: the chain cannot be trusted, so no history is offered for this
            // conversation rather than a diff computed against something that failed to parse.
            return $this->seed(count($current->turns));
        }

        [$from, $baseline] = $states;
        $lineages = $this->seed(count($baseline->turns));
        $previous = $baseline;

        for ($i = $from; $i < count($revisions); $i++) {
            $revision = $revisions[$i];
            $next = $i + 1 < count($revisions)
                ? ReviewedConversationTurns::fromJson($revisions[$i + 1]->segmentsJson)
                : $current;

            $lineages = $this->apply($lineages, $previous, $next, $revision);
            $previous = $next;
        }

        return $lineages;
    }

    /**
     * Where to start, and from which conversation.
     *
     * Everything up to and including the last revert describes a conversation that was thrown away, so
     * the walk begins after it — from the state that revert produced, which is the machine's own.
     *
     * @param list<SegmentRevision> $revisions
     *
     * @return array{0: int, 1: ReviewedConversationTurns}|null null when a snapshot could not be read
     */
    private function statesFrom(array $revisions, ReviewedConversationTurns $current): ?array
    {
        $start = 0;
        foreach ($revisions as $index => $revision) {
            if ($revision->operation === ReviewOperation::Revert) {
                $start = $index + 1;
            }
        }

        if ($start >= count($revisions)) {
            // The last correction was a revert: the conversation on screen is the machine's, and nothing
            // that happened before is about it.
            return [count($revisions), $current];
        }

        $baseline = ReviewedConversationTurns::fromJson($revisions[$start]->segmentsJson);

        // fromJson() answers an unparseable snapshot with an empty conversation, which is
        // indistinguishable from a genuinely empty one and would make the first diff replace everything.
        if ($baseline->isEmpty() && !$current->isEmpty()) {
            return null;
        }

        return [$start, $baseline];
    }

    /**
     * Carry every lineage across one correction.
     *
     * @param list<TurnLineage> $lineages one per turn of `$before`
     *
     * @return list<TurnLineage> one per turn of `$after`
     */
    private function apply(
        array $lineages,
        ReviewedConversationTurns $before,
        ReviewedConversationTurns $after,
        SegmentRevision $revision,
    ): array {
        $diff = ConversationDiff::between($before->turns, $after->turns);

        // Confirming roles, or any other operation that left every message alone. There is no message to
        // attribute it to, so the lineages pass through untouched.
        if ($diff->isEmpty()) {
            return $lineages;
        }

        $consumed = array_slice($lineages, $diff->prefix, $diff->lengthBefore);

        if ($diff->lengthBefore > self::MAX_ATTRIBUTABLE_RUN || $diff->lengthAfter > self::MAX_ATTRIBUTABLE_RUN) {
            // Too broad to name individual messages honestly. The produced turns inherit what the
            // messages they replaced already carried, and this operation itself is not attributed.
            return $this->rebuild($lineages, $diff, $consumed, null);
        }

        $event = new HistoryEvent(
            $revision->revisionNumber,
            $revision->operation,
            $revision->editedByUsername,
            $revision->createdAt,
            array_slice($before->turns, $diff->prefix, $diff->lengthBefore),
            array_slice($after->turns, $diff->prefix, $diff->lengthAfter),
        );

        return $this->rebuild($lineages, $diff, $consumed, $event);
    }

    /**
     * @param list<TurnLineage> $lineages
     * @param list<TurnLineage> $consumed
     *
     * @return list<TurnLineage>
     */
    private function rebuild(array $lineages, ConversationDiff $diff, array $consumed, ?HistoryEvent $event): array
    {
        $head = array_slice($lineages, 0, $diff->prefix);
        $tail = array_slice($lineages, $diff->prefix + $diff->lengthBefore);

        $produced = [];
        for ($i = 0; $i < $diff->lengthAfter; $i++) {
            // Every message the operation produced carries the same event and the same inherited past.
            // Where it produced more than one, that shared event is the honest record: the data shows
            // which messages were involved, never which came from which.
            $produced[] = $event === null
                ? $this->inherit($consumed)
                : (new TurnLineage())->with($event, $consumed);
        }

        return [...$head, ...$produced, ...$tail];
    }

    /**
     * @param list<TurnLineage> $consumed
     */
    private function inherit(array $consumed): TurnLineage
    {
        $events = [];
        foreach ($consumed as $lineage) {
            foreach ($lineage->events as $event) {
                $events[$event->revisionNumber] = $event;
            }
        }

        if ($events === []) {
            return new TurnLineage();
        }

        krsort($events);

        return new TurnLineage(array_values($events));
    }

    /**
     * @return list<TurnLineage>
     */
    private function seed(int $count): array
    {
        $lineages = [];
        for ($i = 0; $i < $count; $i++) {
            $lineages[] = new TurnLineage();
        }

        return $lineages;
    }
}
