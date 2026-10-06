<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function count;

/**
 * When the *other* side of a call was speaking, as two lists of instants.
 *
 * ## Why this is confirmation and never a position
 *
 * A deterministic call arrives as two recordings of one conversation, so each channel holds the silence
 * of whichever party is listening. That silence is where a turn really ends — and whisper.cpp does not
 * report it. Measured on two synchronized Order58 calls: it stretches the surrounding words instead,
 * giving `" sauce"` 4760 ms and `"?"` 1700 ms of duration, so the gap a turn boundary would be found at
 * has been absorbed into a word.
 *
 * Which means the sibling's timings cannot be used to *place* a cut. Taking the other party's turn end
 * as a cut point directly was measured and is much worse: 38 turns became 57, mid-phrase openings went
 * from 24% to 47%, and on one call both candidate positions fell inside the word "rangoon". So the cut
 * position always comes from the current channel's own punctuation, and this evidence only answers
 * "did the conversation actually change hands around there".
 *
 * ## The two lists are different questions
 *
 * `floorTakenAt` holds the other channel's turn **starts** — "they began speaking". `handoverAt` holds
 * its turn **ends** — "they finished speaking". They repair different defects and neither subsumes the
 * other: on call 22414839 only a start could separate `"Okay, that's fine."` from the sentence the
 * Customer resumed after the Agent replied, and on 22613839 only an end could separate the Agent's
 * `"BBQ chicken wings?"` from the `"Anything else?"` they asked after the Customer answered.
 *
 * @see \App\AudioToText\Application\Speaker\SingleSpeakerUtteranceSegmenter for the rules that read this
 * @see \App\AudioToText\Application\Speaker\CrossChannelEvidenceReader for the eligibility guard
 */
final readonly class CrossChannelEvidence
{
    /**
     * @param list<int> $floorTakenAt the other channel's turn start times, ascending
     * @param list<int> $handoverAt   the other channel's turn end times, ascending
     */
    private function __construct(
        public array $floorTakenAt,
        public array $handoverAt,
    ) {}

    /**
     * The sibling's stored turns as evidence, or **null when its timeline cannot be trusted as a clock**.
     *
     * The predicate is the one {@see \App\AudioToText\Application\Combined\CombinedConversationReader}
     * already applies before interleaving two children: a turn ending before it starts, turns running
     * backwards, or every turn sharing one instant all describe a transcript whose numbers were never
     * measured. Reproduced here rather than extracted because that class is a projection and this is the
     * write path — they must be able to disagree about a row without one of them changing.
     *
     * A single turn is rejected here, unlike there. One turn is enough to render a thread in order; it
     * is not enough to say when a conversation changed hands, which is the only thing this is for.
     *
     * @param list<SpeakerUtterance> $turns
     */
    public static function fromSiblingTurns(array $turns): ?self
    {
        if (count($turns) < 2) {
            return null;
        }

        $starts = [];
        $ends = [];
        $previousStart = null;
        $distinct = [];

        foreach ($turns as $turn) {
            if ($turn->endMs < $turn->startMs) {
                return null;
            }

            if ($previousStart !== null && $turn->startMs < $previousStart) {
                return null;
            }

            $previousStart = $turn->startMs;
            $distinct[$turn->startMs] = true;
            $starts[] = $turn->startMs;
            $ends[] = $turn->endMs;
        }

        // Every turn at the same instant carries no ordering information at all, so it cannot confirm a
        // handover either. That is a transcript stored without timings, not a conversation.
        if (count($distinct) < 2) {
            return null;
        }

        return new self($starts, $ends);
    }
}
