<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * Which gate a recording stopped at on its way to a published Agent/Customer split.
 *
 * ## Why this exists
 *
 * `speaker_separation_status` has only ever said NEEDS_REVIEW. That is one word for six quite different
 * situations, and they need different answers: a recording the diarizer heard as one voice cannot be
 * rescued by better role rules, and a well-separated call that simply never reached an address question
 * is not a diarization problem. Reading the sentence in the log told you which — for the one job you
 * happened to look at, if the log had not rotated. Nothing in the database said, so nothing could count
 * them, and a decision about where to spend effort was a guess about proportions.
 *
 * ## It is a diagnosis, not a decision
 *
 * **Nothing reads this to decide anything.** It is written after the outcome has already been settled by
 * {@see \App\AudioToText\Application\Speaker\SpeakerSeparationService}, records why that outcome
 * happened, and is shown to an administrator. No status, no confidence, no role, no publication and no
 * threshold depends on it, and none may be made to: the moment a branch reads this value it stops being
 * an observation and becomes part of the classifier, which is a change to behaviour wearing the clothes
 * of a diagnostic.
 *
 * ## There is no case for "not one word matched a speaker"
 *
 * That outcome is FAILED rather than NEEDS_REVIEW, and FAILED already says in one word that the stage
 * produced no result. A recording in that state offers a person nothing to confirm — there are no two
 * sides to choose between — so a review diagnosis on it would be answering a question nobody asked.
 *
 * ## The order is the pipeline's order
 *
 * The cases below are listed in the order the gates run, and a recording is described by the **first**
 * one it fails. That matters when reading a count of them: a call that has both an unusable speaker
 * balance and no role evidence is recorded against the balance, because the mapper never ran on it.
 *
 * ## Why an enum and not the sentence
 *
 * The sentences are written for a person and carry measured numbers — shares, seconds, word counts —
 * which is exactly what makes them useless to group by. This is the stable half, safe to count, filter
 * and compare across releases; the sentence stays in the log beside it for the detail.
 */
enum SeparationReviewReason: string
{
    /** Below the share of speech that has to land on a speaker before a split is worth publishing. */
    case LOW_ATTRIBUTED_SHARE = 'LOW_ATTRIBUTED_SHARE';

    /**
     * There were not two speakers worth the name.
     *
     * One voice, or a second so slight — under a share, a duration, a word count or a number of
     * alternations — that calling it a second party would be generous. Asked **before** which one is
     * the agent, and independently of it: the role mapper can be perfectly confident about a cluster
     * containing three words.
     */
    case UNUSABLE_SPEAKER_BALANCE = 'UNUSABLE_SPEAKER_BALANCE';

    /**
     * The mapper found only one speaker among the utterances it was given.
     *
     * Distinct from {@see UNUSABLE_SPEAKER_BALANCE}, which is measured on the whole recording. Reached
     * when the balance gate passed but the utterances the mapper walked held a single cluster.
     */
    case ONE_SPEAKER_DETECTED = 'ONE_SPEAKER_DETECTED';

    /**
     * Two speakers, and nothing in what either said says which is which.
     *
     * The commonest honest failure: a short call, or one that never reached a question the other side
     * answered. No amount of diarization improves it — the evidence is not in the audio, it is absent
     * from the conversation.
     */
    case NO_ROLE_SIGNALS = 'NO_ROLE_SIGNALS';

    /** Signals on both sides, too evenly split or too few of them to call. */
    case ROLE_CONFIDENCE_LOW = 'ROLE_CONFIDENCE_LOW';

    /**
     * A mapping was made and one of the two roles came out with no speech at all.
     *
     * Whatever the score said, that is not a two-party split, and publishing it would present half a
     * conversation as a whole one.
     */
    case EMPTY_ROLE_TEXT = 'EMPTY_ROLE_TEXT';

    /**
     * What to tell an administrator looking at the recording.
     *
     * Written for somebody deciding whether to press Confirm, not for somebody debugging the classifier:
     * each one says what was found and, where there is one, what would help. No thresholds, no field
     * names, no mention of gates or mappers — those are in the log, where they belong.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::LOW_ATTRIBUTED_SHARE
                => 'Too little of the speech could be matched to a speaker for the split to be trusted. '
                . 'The transcript itself is complete and unaffected.',
            self::UNUSABLE_SPEAKER_BALANCE
                => 'Only one usable speaker was detected. Either one person does nearly all the talking, '
                . 'or the two voices were too alike to tell apart.',
            self::ONE_SPEAKER_DETECTED
                => 'Only one speaker was detected in this recording, so there are no two sides to assign.',
            self::NO_ROLE_SIGNALS
                => 'Two speakers were separated, but nothing either of them said shows which is the agent '
                . 'and which is the customer — no order, address, payment or delivery exchange was '
                . 'found. Confirm the roles below if you can tell from the conversation.',
            self::ROLE_CONFIDENCE_LOW
                => 'Two speakers were separated, but there was not enough evidence to identify the agent '
                . 'and the customer confidently. Confirm the roles below if you can tell from the '
                . 'conversation.',
            self::EMPTY_ROLE_TEXT
                => 'The roles were worked out but one of them ended up with no speech, so the result would '
                . 'show half the conversation. Confirm the roles below if you can tell from it.',
        };
    }

    /**
     * Whether a person reading the conversation could plausibly settle this themselves.
     *
     * True where two speakers were separated and only the *labelling* is open — pressing Confirm then
     * finishes the job. False where there is no usable split underneath, and confirming would be
     * putting two names on one voice.
     *
     * Offered so a screen can word its prompt honestly. Nothing about whether Confirm is *available*
     * depends on it; that gate is unchanged and lives where it always did.
     */
    public function aHumanCouldDecide(): bool
    {
        return $this === self::NO_ROLE_SIGNALS
            || $this === self::ROLE_CONFIDENCE_LOW
            || $this === self::EMPTY_ROLE_TEXT;
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
