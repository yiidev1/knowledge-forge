<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Application\Settings\UtteranceSettings;
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
     * @param list<TranscriptToken> $tokens in the order the engine produced them
     *
     * @return list<SpeakerUtterance> chronological, never empty unless `$tokens` is
     */
    public function segment(array $tokens, SpeakerRole $role): array
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

            if ($this->endsHere($current, $previous, $token)) {
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
     * Two independent reasons, and the order matters: the safety cap is checked first so a monologue
     * with no pauses in it is still broken up.
     *
     * @param list<TranscriptToken> $current
     */
    private function endsHere(array $current, TranscriptToken $previous, TranscriptToken $token): bool
    {
        $startedAt = $current[0]->startMs;

        if ($token->endMs - $startedAt > $this->settings->maxDurationMs) {
            return true;
        }

        // Overlapping timings make the gap negative. That is not silence, so it is not a boundary.
        return $token->startMs - $previous->endMs >= $this->settings->gapMs;
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

            // Folded into the sentence it interrupts, unless that sentence had already finished.
            if ($tooShort && !$this->endsSentence($previous)) {
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
                continue;
            }

            $usable[] = $text === $token->text
                ? $token
                : new TranscriptToken($token->startMs, $token->endMs, $text);
        }

        return $usable;
    }
}
