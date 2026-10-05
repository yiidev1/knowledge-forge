<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Combined;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\CombinedChild;
use App\AudioToText\Domain\Speaker\CombinedConversation;
use App\AudioToText\Domain\Speaker\CombinedConversationState;
use App\AudioToText\Domain\Speaker\CombinedTurn;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\ResponseTiming;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\ReviewedTurn;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TurnTiming;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;

use function count;
use function usort;

/**
 * The conversation a mixed recording of a deterministic call shows, assembled from its two channels.
 *
 * ## The problem it answers
 *
 * When a call arrives as three files and both single-speaker channels are transcribed on their own, the
 * mixed file has nothing left to contribute but audio: transcribing it again would produce a third,
 * disagreeing account of the same words, and diarizing it would re-guess an attribution that arrived
 * already known. So the mixed recording stores no transcript, and its conversation is **read** from the
 * Customer and Agent rows every time somebody opens it.
 *
 * This is the mirror image of {@see \App\AudioToText\Application\SharedConversationReader}, which runs
 * the other way for a legacy call: there the mixed recording was the only place the two speakers had
 * been told apart, so the channel pages borrowed from it. Both exist because the direction of the
 * borrowing depends on where the attribution actually came from, and a deterministic call is the case
 * where it came from the recordings themselves.
 *
 * ## Nothing is copied and nothing is written
 *
 * The Customer row is authoritative for the Customer's words and the Agent row for the Agent's. A
 * correction saved from this view goes to the child that owns the message, and the next read of either
 * screen shows it — there is one copy, so there is nothing to synchronise and nothing that can drift.
 * {@see CombinedTurn} carries the address of that one copy on every single message.
 *
 * ## Why ambiguity refuses rather than chooses
 *
 * Two recordings of the same side is a normal state: a replacement uploaded beside the original, or the
 * same file imported twice. Nothing in this application records which of them speaks for the call, so
 * picking the newest or the longest would be inventing a rule — and the consequence is not a cosmetic
 * one, because two halves of the same exchange could then come from two different recordings of it. The
 * projection reports {@see CombinedConversationState::AmbiguousChildren} and shows no words at all.
 */
final readonly class CombinedConversationReader
{
    public function __construct(
        private AudioConversationRepositoryInterface $conversations,
        private TranscriptionJobRepositoryInterface $jobs,
        /**
         * The same authority every other screen reads through: the reviewed layer when a child has one,
         * the machine's own segments otherwise. A combined view must never reach past it for a raw
         * column, or a correction would be visible on the channel page and not here.
         */
        private EffectiveConversationReader $effective,
    ) {}

    /**
     * The combined conversation for a mixed recording, or null when this job is not one.
     *
     * Null means **not applicable** and is the ordinary answer: this job is not the mixed recording of
     * an upload, so it has nothing to borrow and the caller renders exactly what it renders today.
     *
     * Every mixed recording gets an answer, including one whose call has no channels and one that
     * belongs to no group at all. That is deliberate: a mixed recording is never transcribed any more,
     * so "no words here" is its ordinary state rather than an error, and it still has audio to play and
     * a reason to give. Refusing would leave the dialog with nothing to say about the commonest
     * recording there is.
     */
    public function for(TranscriptionJob $mixed): ?CombinedConversation
    {
        $conversationId = $mixed->conversationId;

        if ($conversationId === null) {
            return null;
        }

        // Mixed only. A channel recording shows its own words — it is one of the two sources here — and
        // an untyped conversation predates recording types, so it is a recording from before any of this
        // existed and keeps whatever it already had.
        if ($this->conversations->recordingTypeFor($conversationId) !== RecordingType::Mixed) {
            return null;
        }

        // Required for either grouping: a call session id is the provider's, not ours, and an order
        // number is the restaurant's, so scoping to one store is what stops a collision between two
        // restaurants resolving somebody else's recording as this call's Agent.
        $storeSourceId = $this->conversations->storeSourceIdFor($conversationId);

        if ($storeSourceId === null) {
            return CombinedConversation::unusable(CombinedConversationState::NoChildren);
        }

        $ids = $this->siblingJobIds($conversationId, $storeSourceId);

        if ($ids === null) {
            // Neither a call nor an order: there is no group this recording belongs to, so there is
            // nothing it could ever be combined with. Still an answer rather than a refusal — this is a
            // mixed recording, and it has audio and a reason, which is what the dialog needs.
            return CombinedConversation::unusable(CombinedConversationState::NoChildren);
        }

        $callerIds = $ids[RecordingType::Caller->value] ?? [];
        $calleeIds = $ids[RecordingType::Callee->value] ?? [];

        if (count($callerIds) > 1 || count($calleeIds) > 1) {
            return CombinedConversation::unusable(CombinedConversationState::AmbiguousChildren);
        }

        if ($callerIds === [] && $calleeIds === []) {
            return CombinedConversation::unusable(CombinedConversationState::NoChildren);
        }

        // Caller is the Customer and Callee is the Agent. Not decided here: it is the product's
        // existing display contract, and {@see \App\Shared\Audio\RecordingTypeLabels} is where it is
        // written down. Repeating the mapping rather than inheriting it would be a second opinion.
        $customer = $this->child($callerIds[0] ?? null, SpeakerRole::CUSTOMER);
        $agent = $this->child($calleeIds[0] ?? null, SpeakerRole::AGENT);

        if ($customer === null && $agent === null) {
            // The conversation rows exist but their jobs do not — a deletion mid-read. Reported as no
            // children rather than as an error: it is the same thing to the reader.
            return CombinedConversation::unusable(CombinedConversationState::NoChildren);
        }

        [$turns, $interleaved] = $this->project($customer, $agent);

        $state = $this->stateOf($customer, $agent);

        if (!$interleaved) {
            $state = CombinedConversationState::worseOf($state, CombinedConversationState::InvalidTimeline);
        }

        return CombinedConversation::of($state, $turns, $customer, $agent, $interleaved);
    }

    /**
     * The caller and callee recordings of this recording's group, or null when it has no group.
     *
     * Two groupings, tried in the order of how precisely they identify a call. **The call session wins
     * whenever there is one**: it is the provider's own id for one conversation, and only the importer
     * writes it. An order is the fallback, because an order can hold more than one call — two customers
     * ringing about the same order number would merge into one thread if it were ever preferred.
     *
     * The order grouping is not a convenience either: it is how the store page has always grouped
     * recordings, and how an operator builds a Customer + Agent set by hand with the "+ Add audio"
     * controls on a row. Without it a manually uploaded mixed recording could never show a combined
     * conversation, however many sides were added beside it.
     *
     * @return array<string, list<int>>|null
     */
    private function siblingJobIds(int $conversationId, int $storeSourceId): ?array
    {
        $callSessionId = $this->conversations->callSessionFor($conversationId);

        if ($callSessionId !== null) {
            return $this->conversations->channelJobIdsForCallSession($storeSourceId, $callSessionId);
        }

        $orderId = $this->conversations->orderIdFor($conversationId);

        if ($orderId === null) {
            return null;
        }

        return $this->conversations->channelJobIdsForOrder($storeSourceId, $orderId);
    }

    /**
     * One side, loaded with everything a correction against it will need.
     *
     * `review_count` is read here, in the same breath as the turns, so the version a form carries
     * describes the turns the form was rendered from. Reading it separately later would open a window
     * where the page shows one state and locks against another.
     */
    private function child(?int $jobId, SpeakerRole $role): ?CombinedChild
    {
        if ($jobId === null) {
            return null;
        }

        $job = $this->jobs->findById($jobId);

        if ($job === null) {
            return null;
        }

        $effective = $this->effective->for($job);

        $turns = $job->isReviewed()
            ? ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson)
            : ReviewedConversationTurns::fromUtterances($effective->utterances);

        return new CombinedChild(
            $job->id,
            $job->publicId,
            $role,
            $job->reviewCount,
            $job->status,
            $job->isReviewed(),
            $turns,
        );
    }

    /**
     * How much of the call is here, as one state.
     *
     * Each side is judged on its own and the more urgent answer wins — see
     * {@see CombinedConversationState::worseOf()}. A side is "absent" only when no recording of it
     * exists; a recording that exists but holds no transcript yet is reported as that, because the two
     * need different sentences and different actions.
     */
    private function stateOf(?CombinedChild $customer, ?CombinedChild $agent): CombinedConversationState
    {
        $candidates = [];

        if ($customer === null) {
            // The Agent is all there is, which is a statement about the Agent's completeness.
            $candidates[] = CombinedConversationState::AgentOnly;
        } elseif ($customer->status === JobStatus::FAILED) {
            $candidates[] = CombinedConversationState::CustomerFailed;
        } elseif ($customer->status !== JobStatus::COMPLETED) {
            $candidates[] = CombinedConversationState::CustomerProcessing;
        }

        if ($agent === null) {
            $candidates[] = CombinedConversationState::CustomerOnly;
        } elseif ($agent->status === JobStatus::FAILED) {
            $candidates[] = CombinedConversationState::AgentFailed;
        } elseif ($agent->status !== JobStatus::COMPLETED) {
            $candidates[] = CombinedConversationState::AgentProcessing;
        }

        $state = CombinedConversationState::Complete;

        foreach ($candidates as $candidate) {
            $state = CombinedConversationState::worseOf($state, $candidate);
        }

        return $state;
    }

    /**
     * The merged thread, and whether merging it was legitimate.
     *
     * ## Where the owner-local index comes from
     *
     * Each child's list is walked here, with `$index` being that child's own position, and the index is
     * written into an entry **before anything is sorted**. The sort then moves whole entries that
     * already know their own address. There is no step at which a combined position could be mistaken
     * for a local one, because after the sort the local index is a value that was carried in rather
     * than a number that can be recomputed.
     *
     * ## The sort
     *
     * By `startMs`, with the append order as the tie-break. Overlaps are kept and no timestamp is
     * adjusted: two people talking over each other is a fact about the call, and nudging one of them
     * later to make the thread read tidily would be editing the record. PHP's sort is stable, and the
     * explicit tie-break is there so the guarantee is the code's rather than the runtime's.
     *
     * @return array{list<CombinedTurn>, bool}
     */
    private function project(?CombinedChild $customer, ?CombinedChild $agent): array
    {
        /** @var list<array{child: CombinedChild, index: int, turn: ReviewedTurn, seq: int}> $entries */
        $entries = [];
        $seq = 0;

        // Customer first, so a sectioned render and a tie in the merge both resolve the same way.
        foreach ([$customer, $agent] as $child) {
            if ($child === null) {
                continue;
            }

            foreach ($child->turns->turns as $index => $turn) {
                $entries[] = ['child' => $child, 'index' => $index, 'turn' => $turn, 'seq' => $seq];
                $seq++;
            }
        }

        if ($entries === []) {
            return [[], true];
        }

        $interleaved = $this->timelineIsUsable($customer, $agent);

        if ($interleaved) {
            usort(
                $entries,
                /**
                 * @param array{child: CombinedChild, index: int, turn: ReviewedTurn, seq: int} $a
                 * @param array{child: CombinedChild, index: int, turn: ReviewedTurn, seq: int} $b
                 */
                static function (array $a, array $b): int {
                    $byStart = $a['turn']->startMs <=> $b['turn']->startMs;

                    // The append order breaks a tie, which puts the Customer first when both sides
                    // start at the same instant. Spelled out rather than left to the sort's own
                    // stability so the guarantee is this code's and a test can hold it to it.
                    return $byStart === 0 ? $a['seq'] <=> $b['seq'] : $byStart;
                },
            );
        }

        $timings = ResponseTiming::forUtterances($this->asUtterances($entries));

        $turns = [];

        foreach ($entries as $position => $entry) {
            $child = $entry['child'];
            $turn = $entry['turn'];

            $turns[] = new CombinedTurn(
                $child->jobPublicId,
                // Carried in, never derived from `$position`. See the docblock above.
                $entry['index'],
                $child->reviewCount,
                $child->role,
                $turn->startMs,
                $turn->endMs,
                $timings[$position] ?? TurnTiming::untimed(),
                $turn->text,
                $turn->edited,
                $turn->approx,
                // The owner's own verdict, from the owner's own list. A message's neighbour for the
                // purpose of a merge is the message beside it **in its own recording**, which in a
                // combined thread is very often not the bubble above it on screen.
                $child->turns->manualMergeAvailability($entry['index'], MergeDirection::Previous),
                $child->turns->manualMergeAvailability($entry['index'], MergeDirection::Next),
            );
        }

        return [$turns, $interleaved];
    }

    /**
     * Whether the two timelines can be trusted to order the call.
     *
     * Three ways they cannot: a turn that ends before it starts, a recording whose turns run backwards,
     * and a whole projection sharing one start time — which is what a transcript written with no
     * measured timings looks like. In each case the thread is rendered as two sections instead, because
     * interleaving on those numbers would assert a sequence that was never measured.
     *
     * A single turn is always usable: there is nothing to order.
     */
    private function timelineIsUsable(?CombinedChild $customer, ?CombinedChild $agent): bool
    {
        $starts = [];
        $total = 0;

        foreach ([$customer, $agent] as $child) {
            if ($child === null) {
                continue;
            }

            $previousStart = null;

            foreach ($child->turns->turns as $turn) {
                if ($turn->endMs < $turn->startMs) {
                    return false;
                }

                if ($previousStart !== null && $turn->startMs < $previousStart) {
                    return false;
                }

                $previousStart = $turn->startMs;
                $starts[$turn->startMs] = true;
                $total++;
            }
        }

        // Every turn at the same instant carries no ordering information, so the thread is sectioned
        // instead. One turn is the exception: there is nothing to order, and a lone message must not be
        // presented as an unreadable timeline.
        return $total <= 1 || count($starts) > 1;
    }

    /**
     * The merged entries as utterances, only so the existing timing rule can be run over them.
     *
     * Synthetic and discarded immediately: nothing is stored and no caller sees them. `speaker` is set
     * to the role so that {@see ResponseTiming} reads a Customer turn following an Agent turn as a
     * reply. The children's own stored `speaker` is the same channel marker on both sides, so passing
     * it through would make every message of the call look like a continuation of the last and report
     * no response times at all.
     *
     * @param list<array{child: CombinedChild, index: int, turn: ReviewedTurn, seq: int}> $entries
     *
     * @return list<SpeakerUtterance>
     */
    private function asUtterances(array $entries): array
    {
        $utterances = [];

        foreach ($entries as $entry) {
            $turn = $entry['turn'];

            $utterances[] = new SpeakerUtterance(
                $turn->startMs,
                $turn->endMs,
                $entry['child']->role->value,
                $entry['child']->role,
                $turn->text,
                $turn->confidence,
                $turn->approx,
                $turn->edited,
            );
        }

        return $utterances;
    }
}
