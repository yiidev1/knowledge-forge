<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Application\Settings\UtteranceSettings;
use App\AudioToText\Domain\Speaker\CrossChannelEvidence;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptControlTokens;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;

use function array_pop;
use function count;
use function end;
use function preg_match;
use function trim;
use function usort;

/**
 * Cuts one speaker's token stream into utterances, using the silence the speech provider already
 * measured.
 *
 * ## What this replaces
 *
 * A caller or callee recording holds one side of a call. Until now those were handed to the two-speaker
 * diarizer, which — correctly — found a single cluster and produced a single segment spanning the whole
 * file: a four-minute call arriving as one bubble. This does the job the diarizer was never the right
 * tool for, at a fraction of the cost, and without a second model.
 *
 * ## Silence, not punctuation
 *
 * The boundary signal is the gap between one word ending and the next beginning, which both engines
 * already give us: whisper.cpp through `-ojf` token offsets and Deepgram through per-word `start`/`end`.
 * {@see TranscriptToken} normalises them to the same shape, so one implementation serves both.
 *
 * Punctuation is a **veto, never a trigger**. A gap that lands mid-sentence — after "with $2", before
 * "each." — is a breath, and splitting there produces a two-word bubble that reads as a transcription
 * fault. So a short fragment is only allowed to stand alone when the words before it also *end* like a
 * sentence. Punctuation alone never creates a boundary: the same measurement found several sentence
 * endings with no pause at all after them, and splitting on those would cut a speaker mid-flow.
 *
 * ## Why ffmpeg silence detection is not used
 *
 * It was measured and rejected. On the supplied sample channels `silencedetect` reports **zero** silences
 * of 0.7 s or longer at −30, −40, −50 and −60 dB: the quiet while the other party talks is not digital
 * silence but a continuous noise floor. The provider's word timings are the only usable signal.
 *
 * ## Degrading safely
 *
 * Tokens with unusable timings cannot be cut on silence, and guessing from punctuation would dress a
 * guess as a measurement. The whole stream becomes one utterance instead — the behaviour of today,
 * which is honest about knowing nothing rather than inventing boundaries.
 */
final readonly class SingleSpeakerUtteranceSegmenter
{
    /**
     * The cluster label a single-channel recording carries.
     *
     * Not `SPEAKER_00`: nothing was clustered here. The role came from the channel the file arrived on,
     * and the label says so rather than implying a diarizer had an opinion.
     */
    public const CHANNEL_SPEAKER = 'CHANNEL';

    public function __construct(private UtteranceSettings $settings) {}

    /**
     * How long after a sentence ends the other party may take the floor and still be answering it.
     *
     * Measured, not configurable. On the two synchronized calls available the real handovers sat 2050 ms
     * and 2340 ms after the speaker's own sentence ended, and both channels of both calls were checked
     * at 1000, 2000 and 3000 ms: below 3000 the genuine `"Okay, that's fine."` handover is missed, and
     * nothing new appears between 2000 and 3000. It is a constant rather than a setting because it
     * describes how people answer each other, not how this deployment is configured — a server with a
     * different value here would be producing differently-shaped turns for no stated reason.
     */
    private const FLOOR_WINDOW_MS = 3000;

    /**
     * @param list<TranscriptToken>   $tokens   in the order the engine produced them
     * @param CrossChannelEvidence|null $evidence when the opposite channel of this same call is already
     *                                            transcribed and its timings are trustworthy; null — the
     *                                            default, and every case but a deterministic Order58
     *                                            sibling — produces exactly the single-channel result
     *
     * @return list<SpeakerUtterance> chronological, never empty unless `$tokens` is
     */
    public function segment(array $tokens, SpeakerRole $role, ?CrossChannelEvidence $evidence = null): array
    {
        $usable = $this->usable($tokens);

        if ($usable === []) {
            return [];
        }

        // Engines emit in order, but a malformed response must not reorder the text it produces: sorting
        // by start keeps the transcript readable even when a timing is wrong.
        usort($usable, static fn(TranscriptToken $a, TranscriptToken $b): int => $a->startMs <=> $b->startMs);

        /** @var list<list<TranscriptToken>> $groups */
        $groups = [];
        $current = [$usable[0]];

        for ($i = 1, $n = count($usable); $i < $n; $i++) {
            $token = $usable[$i];
            $previous = $usable[$i - 1];

            if ($this->endsHere($current, $previous, $token, $evidence)) {
                $groups[] = $current;
                $current = [$token];

                continue;
            }

            $current[] = $token;
        }

        $groups[] = $current;

        return $this->toUtterances($this->absorbFragments($groups), $role);
    }

    /**
     * Whether the boundary between two consecutive tokens ends the utterance being built.
     *
     * ## Three reasons, in this order
     *
     * 1. **The safety cap.** A monologue with no pause anywhere in it is still broken up, so nothing
     *    can produce a single bubble spanning a whole call.
     * 2. **A pause where a sentence just finished.** The normal boundary, and the one that reads well.
     * 3. **A pause inside a sentence, once the turn has already run long.** The fallback.
     *
     * ## Why a pause alone is not enough
     *
     * Measured on a real call: the Customer's eleven pauses were 0, 210, 970, 970, 980, 980, 1180,
     * 1180, 1200, 1220 and 1270 ms, and only **one** of their twelve turns ended in terminal
     * punctuation. These speakers hesitate inside a sentence about as often as they finish one, and the
     * two kinds of pause are the same length — so no threshold separates them. Cutting on silence alone
     * produced turns starting ", like super combo for two?" for 22 of 29 turns, which a reader takes for
     * a transcription fault rather than a pause.
     *
     * Raising the threshold does not help and makes it worse: at 1500 ms that Customer channel has no
     * qualifying pause at all and collapses back into the single bubble this class exists to remove.
     *
     * ## Why punctuation alone is not enough either
     *
     * It is still never a trigger. A sentence ending with no pause after it is a speaker carrying
     * straight on, and cutting there would break the flow of the delivery — the same measurement found
     * several sentence endings with no measurable pause at all. Punctuation only decides **which** of
     * the pauses that already qualify are worth cutting at.
     *
     * ## The fallback, and why it has its own threshold
     *
     * Some speakers barely punctuate. Waiting for a sentence that never comes would hand them the safety
     * cap's arbitrary mid-word cut instead. So once a turn has run past `softMaxDurationMs` — long
     * enough to be worth breaking, short enough to still be reading well — any qualifying pause ends
     * it. That is a cut at real silence, chosen because the turn was already long, which is a better
     * reason than "this pause happened to be 970 ms".
     *
     * @param list<TranscriptToken> $current
     */
    private function endsHere(
        array $current,
        TranscriptToken $previous,
        TranscriptToken $token,
        ?CrossChannelEvidence $evidence,
    ): bool {
        $startedAt = $current[0]->startMs;

        if ($token->endMs - $startedAt > $this->settings->maxDurationMs) {
            return true;
        }

        // The engine already ended an utterance here, and that outranks every measurement below.
        //
        // Measured on a real 162-second call: whisper produced 16 sentence-aligned segments, and the
        // old rules reproduced none of them — 4 of 11 turns were cut by the hard cap alone, 8 of 11
        // began mid-phrase. Honouring the boundary took that to 1 and 4. The gap test cannot find these
        // on its own because whisper's segments abut at exactly 0 ms; it reports utterances, not
        // silence, so a rule that waits for silence waits forever and the safety net does the cutting.
        if ($previous->endsProviderUtterance) {
            return true;
        }

        // The other side of this same call, when there is one. Placed after the rules above because it
        // only ever *adds* a boundary the engine did not already give us, and before the gap floor
        // because the silence it reasons about is exactly the silence whisper failed to report.
        if ($evidence !== null && $this->conversationChangedHands($current, $previous, $token, $evidence)) {
            return true;
        }

        // Overlapping timings make the gap negative. That is not silence, so it is not a boundary.
        if ($token->startMs - $previous->endMs < $this->settings->gapMs) {
            return false;
        }

        if ($this->endsSentence($current)) {
            return true;
        }

        return $previous->endMs - $startedAt >= $this->settings->softMaxDurationMs;
    }

    /**
     * Did the conversation change hands between these two words?
     *
     * ## The position is always ours
     *
     * Both rules below begin by requiring that **this channel** has finished a sentence here. That is not
     * a quality filter bolted on afterwards; it is what makes the cut land somewhere real. The sibling's
     * timings are smeared — whisper absorbs a listener's silence into the surrounding words rather than
     * reporting a gap — so a cut placed at the sibling's instant lands wherever that smear happens to
     * fall. Measured on call 22414839: the two positions the sibling's timings pointed at were *inside*
     * the word "rangoon" and *inside* the word "of". Requiring our own sentence end means the cut can
     * only ever fall on a token boundary this channel itself put there, so it can never land inside a
     * word, and it cannot be moved by a timing error on the other side.
     *
     * The sibling answers one question only: did the other party actually respond around here, or were
     * we just pausing? Without it, cutting at every sentence end was measured and rejected — people
     * finish sentences mid-thought constantly, and the existing rules say so at length above.
     *
     * ## Rule A — we finished, they answered
     *
     * Our sentence ended and the other party began speaking within {@see self::FLOOR_WINDOW_MS}. Repairs
     * `"Okay, that's fine. Can I do one order of crab rangoon…"`, where the Customer finished a sentence
     * at 90020 ms, the Agent replied at 92070 ms, and the Customer's next sentence had been glued onto
     * the first because whisper gave the whole stretch one segment.
     *
     * ## Rule B — they finished between our words
     *
     * Our sentence ended and the other party's turn *ended* between our last word and the next one — so
     * they held the floor across the gap and we resumed after them. Repairs
     * `"BBQ chicken wings? Anything"`, where the Agent asked a question, the Customer answered twice, and
     * the Agent's next question was glued onto the first with its own `"else?"` left standing alone.
     *
     * Neither rule subsumes the other and each repaired a case the other could not.
     *
     * @param list<TranscriptToken> $current
     */
    private function conversationChangedHands(
        array $current,
        TranscriptToken $previous,
        TranscriptToken $token,
        CrossChannelEvidence $evidence,
    ): bool {
        if (!$this->endsSentence($current)) {
            return false;
        }

        if ($this->numberContinues($current, $token)) {
            return false;
        }

        $startedAt = $current[0]->startMs;

        // Rule A.
        foreach ($evidence->floorTakenAt as $start) {
            if ($start >= $previous->endMs && $start <= $previous->endMs + self::FLOOR_WINDOW_MS) {
                return true;
            }
        }

        // Rule B. Bounded below by this turn's own start so that a handover we have already cut at
        // cannot cut the same turn a second time.
        foreach ($evidence->handoverAt as $end) {
            if ($end > $startedAt && $end >= $previous->startMs && $end <= $token->startMs) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the punctuation we are about to cut after actually part of a number?
     *
     * `$21.73` is tokenised `" $" | "21" | "." | "73"`, and `"…that would be $21."` satisfies every
     * sentence-end test there is. The first version of Rule B cut there on a real call and turned a
     * price into two turns, `"…that would be $21."` and `"73."` — not a word lost, and the order total
     * destroyed, in an application whose whole purpose is order accuracy.
     *
     * The test is the one {@see \App\AudioToText\Application\SpokenPrice} already relies on, in its own
     * words: *a trailing `.` or `,` only disqualifies the match when a digit follows it* — `"$43 and 45."`
     * ends a sentence, while `"$43 and 45.50"` is a number. So both halves must hold: a digit before the
     * punctuation and a digit after it. `$25.57`, `7.30` and a decimal quantity are all covered, while a
     * genuine sentence end after a price (`"…that would be $21.73."` followed by a word) still cuts.
     *
     * No abbreviation handling, deliberately. `"No."`, `"St."` and `"Mr."` would each need a word list
     * that this has no evidence to build, and the cost of being wrong is asymmetric: a missed cut leaves
     * a transcript that was already acceptable, and a wrong cut damages one. Ambiguity rejects.
     *
     * @param list<TranscriptToken> $current
     */
    private function numberContinues(array $current, TranscriptToken $token): bool
    {
        $text = '';

        foreach ($current as $one) {
            $text .= $one->text;
        }

        return preg_match('/\d[.,]$/u', trim($text)) === 1
            && preg_match('/^\s*\d/u', $token->text) === 1;
    }

    /**
     * Folds a too-short group back into the one before it, unless that one ended like a sentence.
     *
     * This is where punctuation earns its keep: "…please?" followed by a brief "Yes." is two turns, while
     * "…with $2" followed by "each." is one sentence the speaker paused inside.
     *
     * @param list<list<TranscriptToken>> $groups
     * @return list<list<TranscriptToken>>
     */
    private function absorbFragments(array $groups): array
    {
        /** @var list<list<TranscriptToken>> $kept */
        $kept = [];

        foreach ($groups as $group) {
            $last = $this->lastOf($group);
            $tooShort = $last->endMs - $group[0]->startMs < $this->settings->minDurationMs;
            $previous = array_pop($kept);

            if ($previous === null) {
                $kept[] = $group;

                continue;
            }

            // Folded into the sentence it interrupts, unless that sentence had already finished — or
            // unless the engine itself ended an utterance there.
            //
            // The provider exemption is what keeps a real one-word answer standing alone. "Yes." runs
            // 21400-22400 ms on the measured call: a whole second, a complete reply, and well under the
            // 1200 ms minimum. Folding it into the next sentence because of its length would merge an
            // answer into the question that followed it, and the engine had already said they were two.
            //
            // Read off the previous group's last token, which is where the boundary lives.
            $trusted = $this->lastOf($previous)->endsProviderUtterance;

            if ($tooShort && !$trusted && !$this->endsSentence($previous)) {
                $kept[] = [...$previous, ...$group];

                continue;
            }

            $kept[] = $previous;
            $kept[] = $group;
        }

        return $kept;
    }

    /**
     * @param list<TranscriptToken> $group
     */
    private function endsSentence(array $group): bool
    {
        return preg_match(
            '/[.!?\x{2026}][\'"\x{2019}\x{201D})\]]*$/u',
            trim($this->lastOf($group)->text),
        ) === 1;
    }

    /**
     * The final token of a group that is known to be non-empty.
     *
     * Every group this class builds starts with one token and only grows, but `end()` says so to the
     * analyser in a way `$group[count($group) - 1]` does not.
     *
     * @param list<TranscriptToken> $group
     */
    private function lastOf(array $group): TranscriptToken
    {
        $last = end($group);

        return $last === false ? $group[0] : $last;
    }

    /**
     * @param list<list<TranscriptToken>> $groups
     * @return list<SpeakerUtterance>
     */
    private function toUtterances(array $groups, SpeakerRole $role): array
    {
        $utterances = [];

        foreach ($groups as $group) {
            // Plain concatenation: a word-initial token carries its own leading space, which is the only
            // word-boundary signal TranscriptToken guarantees. Trimming the joined result — never the
            // pieces — is what keeps "-S" + "esame" one word.
            $text = '';
            foreach ($group as $token) {
                $text .= $token->text;
            }

            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $utterances[] = new SpeakerUtterance(
                $group[0]->startMs,
                $this->lastOf($group)->endMs,
                self::CHANNEL_SPEAKER,
                $role,
                $text,
                // The role was given, not inferred, so there is no probability to report. 1.0 says the
                // attribution is certain — which for a single-channel recording it is.
                1.0,
            );
        }

        return $utterances;
    }

    /**
     * Tokens this can measure a gap between.
     *
     * A negative start, an end before its start or an empty string cannot contribute a boundary. They
     * are dropped rather than repaired, and if that leaves nothing the caller falls back to one segment.
     *
     * @param list<TranscriptToken> $tokens
     * @return list<TranscriptToken>
     */
    private function usable(array $tokens): array
    {
        $usable = [];

        foreach ($tokens as $token) {
            if ($token->startMs < 0 || $token->endMs < $token->startMs) {
                continue;
            }

            // Markers rather than speech, and removed before anything else looks at this stream. Two
            // things go wrong if they survive: they are stored, shown and read aloud as though somebody
            // had said them, and — because a marker sits *between* two sentences and carries timings
            // that bridge the pause between them — they hide the silence this whole class measures. The
            // first segmentation of a real Agent channel put five sentences in one bubble for exactly
            // that reason.
            $text = TranscriptControlTokens::strip($token->text);

            if (trim($text) === '') {
                // Nothing left to say — but the engine may have ended an utterance on this very token,
                // and whisper routinely does: `[_TT_nnn]` sits at the END of a segment, so the token
                // carrying the boundary is often exactly the one with no words in it.
                //
                // The boundary moves back to the last token that survived. Dropping it with the marker
                // would lose the structure for precisely the segments that needed cleaning, which is
                // the subtlest way this could half-work: most boundaries honoured, some silently not.
                if ($token->endsProviderUtterance && $usable !== []) {
                    // Popped and pushed rather than assigned by index, so the list stays a list.
                    // The emptiness guard above is what makes the annotation true.
                    /** @var TranscriptToken $kept */
                    $kept = array_pop($usable);

                    $usable[] = $kept->endsProviderUtterance
                        ? $kept
                        : new TranscriptToken($kept->startMs, $kept->endMs, $kept->text, true);
                }

                continue;
            }

            $usable[] = $text === $token->text
                ? $token
                // Rebuilt with the cleaned text — and the boundary carried over. Dropping it here would
                // silently discard the provider's structure for exactly the tokens that needed cleaning,
                // which is the subtlest way this feature could half-work.
                : new TranscriptToken(
                    $token->startMs,
                    $token->endMs,
                    $text,
                    $token->endsProviderUtterance,
                );
        }

        return $usable;
    }
}
