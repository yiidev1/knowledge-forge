<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

/**
 * How a single speaker's words are cut into utterances.
 *
 * A recording that holds one side of a call has nothing to diarize — there is only one speaker — but it
 * still has to be broken into messages, or the whole call arrives as one bubble. These are the numbers
 * that decide where one message ends and the next begins.
 *
 * ## Why these are settings and not literals
 *
 * The right gap is a property of the telephony, not of this code: a line with aggressive echo
 * suppression leaves longer pauses than one without. The investigation that preceded this measured a
 * real 241-second two-party call and found the gap distribution flat between 500 ms and 1500 ms — the
 * utterance count moved by about 15% across that whole range — so no single value is obviously correct
 * and the number has to be adjustable without a deploy.
 */
final readonly class UtteranceSettings
{
    public function __construct(
        /**
         * Silence, in milliseconds, that ends an utterance.
         *
         * The default is deliberately conservative. The smallest gap observed between consecutive
         * segments of one speaker in the sample call was 510 ms, so a threshold at or below that would
         * fire inside a sentence; 900 ms sits clear of it while still well under the 2.9–3.9 s median
         * gap between turns. It is a starting point to tune, not a measured optimum — see the class
         * docblock.
         */
        public int $gapMs,
        /**
         * The longest an utterance may run before it is cut regardless of silence.
         *
         * A safety net, not a boundary rule. The same sample contained a 22.6-second run with no
         * qualifying gap anywhere inside it — four sentences the gap rule alone would have left as one
         * bubble, which is the very complaint this feature exists to fix.
         */
        public int $maxDurationMs,
        /**
         * The shortest utterance a gap may create.
         *
         * Below this, the fragment is kept with the previous utterance instead of standing alone. The
         * sample produced fragments like "with $2" and "each." — grammatically mid-sentence, and a
         * message bubble containing two words is noise rather than a turn.
         */
        public int $minDurationMs,
    ) {}
}
