<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

use function count;

/**
 * How long each speaker took to start replying.
 *
 * Computed from the timestamps already in `speaker_segments`; nothing is stored, and no audio is read.
 *
 * ## The rule
 *
 * For each turn, look back to the **immediately preceding usable turn** — skipping only turns that are
 * unusable for timing at all (unattributed speech, or a turn whose timestamps are missing). Then:
 *
 * - that turn is by the **same speaker** → this is a continuation, not a reply. No delay.
 * - that turn is by the **other speaker** → `delay = this.startMs − that.endMs`.
 *
 * The "immediately preceding" part is load-bearing. An earlier draft searched backwards for the nearest
 * turn by a *different* speaker, which quietly attributed a response time to a continuation: where one
 * person speaks twice in a row, the second half would be timed against whatever the other person had
 * said before it, and reported as though they had just replied. Stopping at the first usable turn makes
 * that impossible.
 *
 * A negative gap means the two turns overlap; it is reported as overlapping rather than as a negative
 * number, and never clamped silently to a plausible-looking zero.
 */
final readonly class ResponseTiming
{
    /**
     * Timings positionally aligned to `$utterances` — index *n* describes utterance *n*.
     *
     * @param list<SpeakerUtterance> $utterances
     *
     * @return list<TurnTiming>
     */
    public static function forUtterances(array $utterances): array
    {
        $timings = [];

        /** @var array{speaker: string, startMs: int, endMs: int, approx: bool}|null $previous */
        $previous = null;

        foreach ($utterances as $utterance) {
            if (!self::isUsable($utterance)) {
                // Unusable for timing, and also invisible to the next turn's look-back: it can neither
                // receive a delay nor be the thing a later turn is timed against.
                $timings[] = TurnTiming::untimed();

                continue;
            }

            $timing = TurnTiming::at($utterance->startMs, $utterance->endMs, $utterance->approx);

            if ($previous !== null && $previous['speaker'] !== $utterance->speaker) {
                $gap = $utterance->startMs - $previous['endMs'];

                $timing = $gap < 0
                    ? $timing->overlappingPrevious()
                    : $timing->respondingAfter($gap);
            }

            // Both halves of one split carry the parent's span. Printing it twice would present a
            // single measurement as two.
            if ($previous !== null
                && $previous['approx']
                && $utterance->approx
                && $previous['startMs'] === $utterance->startMs
                && $previous['endMs'] === $utterance->endMs
            ) {
                $timing = $timing->repeatingPreviousRange();
            }

            $timings[] = $timing;

            $previous = [
                'speaker' => $utterance->speaker,
                'startMs' => $utterance->startMs,
                'endMs' => $utterance->endMs,
                'approx' => $utterance->approx,
            ];
        }

        return $timings;
    }

    /**
     * Which side of the thread each turn belongs on.
     *
     * When roles are published the sides mean what they look like. When they are not, the sides follow
     * the neutral cluster so the exchange still reads as a conversation — the labels stay neutral, and
     * the arrangement claims nothing the labels do not.
     *
     * @param list<SpeakerUtterance> $utterances
     *
     * @return list<ConversationSide>
     */
    public static function sidesFor(array $utterances, bool $rolesPublished): array
    {
        $sides = [];
        $clusterSides = [];

        foreach ($utterances as $utterance) {
            if ($utterance->speaker === SpeakerRole::UNKNOWN->value) {
                $sides[] = ConversationSide::Neutral;

                continue;
            }

            if ($rolesPublished) {
                $sides[] = match ($utterance->role) {
                    SpeakerRole::CUSTOMER => ConversationSide::Left,
                    SpeakerRole::AGENT => ConversationSide::Right,
                    default => ConversationSide::Neutral,
                };

                continue;
            }

            // Unpublished: assign sides by order of first appearance, so the two voices stay on
            // consistent sides whichever cluster happens to speak first.
            if (!isset($clusterSides[$utterance->speaker])) {
                $clusterSides[$utterance->speaker] = count($clusterSides) === 0
                    ? ConversationSide::Left
                    : ConversationSide::Right;
            }

            $sides[] = $clusterSides[$utterance->speaker];
        }

        return $sides;
    }

    /**
     * Whether a turn can take part in timing at all.
     *
     * Unattributed speech is excluded because "who replied to whom" is meaningless without a speaker.
     * A turn with both timestamps at zero is excluded because that is what the decoder writes when the
     * stored values were missing or malformed — treating it as position zero would invent a gap.
     */
    private static function isUsable(SpeakerUtterance $utterance): bool
    {
        if ($utterance->speaker === SpeakerRole::UNKNOWN->value) {
            return false;
        }

        return $utterance->startMs > 0 || $utterance->endMs > 0;
    }
}
