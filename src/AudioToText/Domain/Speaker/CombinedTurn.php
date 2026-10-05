<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

/**
 * One message of a deterministic call, and the row it belongs to.
 *
 * ## The whole point of this class
 *
 * A combined conversation is assembled from two independent transcriptions, so a message's position on
 * screen says nothing about where it lives. Visual turn #2 of a five-message call can be Agent local
 * turn #1; the next message down can be Customer local turn #1. Every one of them is corrected through
 * the route of **its own** job at **its own** index, guarded by **its own** `review_count`.
 *
 * So the three owner fields are not metadata — they are the address of the thing being edited, and the
 * combined position is never any part of it. The display index is deliberately not stored here at all:
 * a field holding it would eventually be used, and the one and only way this feature can silently
 * corrupt a transcript is for a correction aimed at one message to land on another.
 *
 * `ownerLocalTurnIndex` is captured while each child's own list is walked, **before** the merge sort
 * runs. Deriving it afterwards from the merged position is the bug this design exists to make
 * impossible, which is why the sort moves whole objects that already know their own address rather than
 * indices into a list that is about to be reordered.
 *
 * ## What it carries beyond the address
 *
 * Only what the chat actually renders or needs to compose a request: the role (which gives the label
 * and the side), the text as stored, the timings, the two edit markers, and the owner-local verdict on
 * each merge direction. Nothing is here for symmetry with the single-job view.
 */
final readonly class CombinedTurn
{
    public function __construct(
        /** Which job owns these words. Every correction to this message posts against this id. */
        public string $ownerJobPublicId,
        /** This message's index **inside its owner's own turn list** — never its combined position. */
        public int $ownerLocalTurnIndex,
        /** The owner's `review_count` as it was read. The optimistic lock for this message. */
        public int $ownerReviewCount,
        /** CUSTOMER or AGENT, from the recording's declared side. Nothing here was diarized. */
        public SpeakerRole $role,
        /**
         * The stored span, exactly as its own recording measured it.
         *
         * Carried raw as well as inside {@see $timing} because the two are used for different things:
         * these are the merge-sort key and the values the timeline-validity check reads, while
         * `$timing` is the rendered label and already has the cross-side pause folded into it.
         */
        public int $startMs,
        public int $endMs,
        /**
         * The same span as it is printed, including how long this side waited before replying.
         *
         * Measured across the merged thread rather than inside one recording, which is the one timing
         * fact a combined view adds: a pause between the Customer finishing and the Agent starting is
         * invisible in either channel on its own, because each file contains only one of them.
         */
        public TurnTiming $timing,
        /** The stored wording, markers and all — what an editor must be seeded with and post back. */
        public string $text,
        /** Whether a person corrected this message's wording. */
        public bool $edited,
        /** Whether this message's boundary was set by hand, so its timings are inherited. */
        public bool $approx,
        /** Whether the owner's own list allows joining this message to the one before it. */
        public MergeRefusal $mergeWithPrevious,
        /** Whether the owner's own list allows joining this message to the one after it. */
        public MergeRefusal $mergeWithNext,
    ) {}

    /**
     * What to print above the bubble.
     *
     * The role's own label, always, with no publish gate to pass: the side was declared by the person
     * who uploaded the recording, not guessed from the audio, so there is no hypothesis here to be
     * careful about. This is exactly why a combined view never shows "Speaker 1".
     */
    public function label(): string
    {
        return $this->role->label();
    }

    /**
     * Which margin the bubble sits on.
     *
     * Fixed by role rather than by order of appearance, because in a combined conversation the role is
     * known for certain and a per-conversation convention would put the Customer on the left in one
     * call and on the right in the next.
     */
    public function side(): ConversationSide
    {
        return $this->role === SpeakerRole::AGENT
            ? ConversationSide::Right
            : ConversationSide::Left;
    }
}
