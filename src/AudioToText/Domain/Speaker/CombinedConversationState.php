<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * How much of a deterministic call the combined projection could actually assemble.
 *
 * ## Why a state rather than an empty list
 *
 * A mixed recording of a deterministic call stores no transcript of its own — the Customer and Agent
 * channels hold the words, and the mixed row keeps only the audio. So "no turns" is the normal reading
 * of every incomplete case, and an empty chat would present a call that is still being transcribed, a
 * call whose Agent side failed, and a call with two conflicting Customer recordings as the same thing:
 * a conversation where nobody said anything. Each of those needs a different sentence on the screen and
 * a different action from the person reading it.
 *
 * The projection therefore always answers with one of these, and the turns it managed to assemble are
 * carried alongside: a call whose Customer side is still processing still shows the Agent side, under a
 * line saying what is missing. Partial is shown as partial; it is never shown as complete and never
 * shown as nothing.
 *
 * ## Precedence, because several can be true at once
 *
 * A call can have an ambiguous Customer *and* a failed Agent. {@see worseOf()} fixes the order so the
 * same call always reports the same state, and the order is by what the reader must do about it:
 * ambiguity needs a person to decide which recording is the real one, a failure needs a retry, and
 * processing needs only patience.
 */
enum CombinedConversationState: string
{
    /** Exactly one Customer and one Agent child, both transcribed, timings usable. */
    case Complete = 'COMPLETE';

    /** One Customer child transcribed; no Agent recording exists for this call at all. */
    case CustomerOnly = 'CUSTOMER_ONLY';

    /** One Agent child transcribed; no Customer recording exists for this call at all. */
    case AgentOnly = 'AGENT_ONLY';

    case CustomerProcessing = 'CUSTOMER_PROCESSING';

    case AgentProcessing = 'AGENT_PROCESSING';

    case CustomerFailed = 'CUSTOMER_FAILED';

    case AgentFailed = 'AGENT_FAILED';

    /**
     * Two or more recordings claim the same side of this call.
     *
     * Nothing in this application records which of them is authoritative, so the projection declines to
     * choose. Picking the newest or the longest would be inventing a rule, and the two sides of one
     * sentence could end up coming from two different recordings of it.
     */
    case AmbiguousChildren = 'AMBIGUOUS_CHILDREN';

    /**
     * Both sides exist and are transcribed, but their timestamps cannot be trusted to order them.
     *
     * The turns are still shown — in two sections, each in its own recording's order. Interleaving on
     * unusable timings would assert an order nobody measured.
     */
    case InvalidTimeline = 'INVALID_TIMELINE';

    /**
     * A mixed recording whose call has no Customer or Agent recording at all.
     *
     * The ordinary state of a mixed recording uploaded on its own, not a fault. Nothing transcribes one,
     * so a call with no sides recorded is a call with no transcript — accepted, and said plainly, rather
     * than resolved by guessing which of two people on one track is the agent.
     */
    case NoChildren = 'NO_CHILDREN';

    /** Whether every turn of this call is present and in a trustworthy order. */
    public function isComplete(): bool
    {
        return $this === self::Complete;
    }

    /** Whether anything is missing, failed, ambiguous or unordered. */
    public function isPartial(): bool
    {
        return $this !== self::Complete;
    }

    /**
     * What to tell the person looking at the screen, or null when there is nothing to warn about.
     *
     * Written as prose rather than a status word because each of these has a different remedy, and a
     * badge reading "PARTIAL" would hide which one applies.
     */
    public function explanation(): ?string
    {
        return match ($this) {
            self::Complete => null,
            self::CustomerOnly => 'Only the Customer side of this call was recorded, so this shows one '
                . 'half of the conversation.',
            self::AgentOnly => 'Only the Agent side of this call was recorded, so this shows one half '
                . 'of the conversation.',
            self::CustomerProcessing => 'The Customer side of this call is still being transcribed. '
                . 'Its messages will appear here when it finishes.',
            self::AgentProcessing => 'The Agent side of this call is still being transcribed. Its '
                . 'messages will appear here when it finishes.',
            self::CustomerFailed => 'Transcription of the Customer side failed, so its messages are '
                . 'missing from this conversation. Retry it from its own recording.',
            self::AgentFailed => 'Transcription of the Agent side failed, so its messages are missing '
                . 'from this conversation. Retry it from its own recording.',
            self::AmbiguousChildren => 'This call has more than one recording of the same side, and '
                . 'nothing here records which is authoritative — so no combined conversation is shown. '
                . 'Open the individual recordings to compare them.',
            self::InvalidTimeline => 'The two sides of this call carry no usable timestamps, so they '
                . 'are shown separately rather than interleaved. The order within each side is its own.',
            self::NoChildren => 'No Customer or Agent transcript is available for this call, so there '
                . 'is nothing to show here yet. This recording holds both speakers on one track and is '
                . 'kept as the playable original; add or import the Customer and Agent recordings of '
                . 'this call to build its conversation.',
        };
    }

    /**
     * The more urgent of two states.
     *
     * Ambiguity outranks failure, failure outranks processing, and anything outranks a complete read.
     * Customer is reported before Agent at equal urgency, so the answer does not depend on which side
     * the reader happened to load first.
     */
    public static function worseOf(self $a, self $b): self
    {
        return self::rank($a) <= self::rank($b) ? $a : $b;
    }

    private static function rank(self $state): int
    {
        return match ($state) {
            self::AmbiguousChildren => 0,
            self::NoChildren => 1,
            self::CustomerFailed => 2,
            self::AgentFailed => 3,
            self::CustomerProcessing => 4,
            self::AgentProcessing => 5,
            self::InvalidTimeline => 6,
            self::CustomerOnly => 7,
            self::AgentOnly => 8,
            self::Complete => 9,
        };
    }
}
