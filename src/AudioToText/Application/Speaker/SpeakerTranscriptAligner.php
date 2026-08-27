<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\Speaker\AlignmentQuality;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\SpeakerRole;

use function count;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function trim;
use function usort;

/**
 * Matches each transcribed token to the speaker who was talking, then groups consecutive same-speaker
 * tokens into utterances.
 *
 * Two timelines are being reconciled, and they disagree in a specific, measurable way. Whisper's token
 * timestamps run **continuously**: a token's span often reaches from the end of one word, across the
 * pause that follows, to the start of the next. The diarizer marks **speech only**, trimming to the
 * parts it is confident about and leaving pauses uncovered. On the reference call it covered 55.9% of
 * the audio across 22 intervals, leaving 21 gaps totalling 31.5 seconds — median 1.2s, longest 3.2s —
 * with **zero** genuine cross-speaker overlap.
 *
 * Matching on overlap alone therefore left a third of all tokens attributed to nobody, purely because
 * they landed in silence. Hence {@see bridgeGap()}: a token in a gap is resolved by proximity rather
 * than discarded. Attribution by token duration rose from 74% to 99% on that recording, and the
 * resulting conversation reads as a coherent two-party exchange.
 *
 * Alignment stays at **token** level. Whisper's segments are far too coarse — one of them on that same
 * call spanned fourteen seconds and about eight speaker turns, which at segment granularity would put
 * both sides of the conversation in one column.
 *
 * Punctuation and sentence order never decide who spoke. They cannot: "Yes." tells you nothing about
 * which of two voices said it.
 */
final readonly class SpeakerTranscriptAligner
{
    /**
     * Below this share of overlap a token is only *weakly* inside its speaker's turn — it straddles a
     * boundary. Still attributed, because greatest-overlap is the right answer there, but tracked.
     */
    private const WEAK_OVERLAP_RATIO = 0.5;

    /**
     * @param list<TranscriptToken> $tokens
     * @param list<SpeakerSegment>  $segments
     * @param int                   $boundaryToleranceMs how far outside every interval a token may sit
     *                                                   and still be attributed to the nearest speaker
     */
    public function align(array $tokens, array $segments, int $boundaryToleranceMs): AlignedTranscript
    {
        if ($tokens === [] || $segments === []) {
            return AlignedTranscript::empty();
        }

        usort($segments, static fn(SpeakerSegment $a, SpeakerSegment $b): int => $a->startMs <=> $b->startMs);

        /** @var list<array{token: TranscriptToken, speaker: string, ratio: float, bridged: bool}> $assigned */
        $assigned = [];
        $totalDuration = 0;
        $attributedDuration = 0;
        $bridgedDuration = 0;
        $attributedTokens = 0;
        $bridgedTokens = 0;

        foreach ($tokens as $token) {
            $text = $this->cleanTokenText($token->text);
            if ($text === '') {
                continue;
            }

            $span = max(1, $token->durationMs());
            $totalDuration += $span;

            [$speaker, $overlap] = $this->bestOverlap($token, $segments);
            $bridged = false;

            if ($speaker === null) {
                // No interval touches this token at all. Almost always a pause the diarizer did not
                // mark, rather than a second voice — so resolve it by proximity instead of discarding.
                $speaker = $this->bridgeGap($token, $segments, $boundaryToleranceMs);
                $bridged = $speaker !== null;
            }

            if ($speaker === null) {
                $assigned[] = [
                    'token' => new TranscriptToken($token->startMs, $token->endMs, $text),
                    'speaker' => SpeakerRole::UNKNOWN->value,
                    'ratio' => 0.0,
                    'bridged' => false,
                ];

                continue;
            }

            // A word cannot be spoken by two people. Whisper emits sub-word tokens, and a turn boundary
            // landing mid-word would otherwise split one across both roles — the reference call produced
            // "-S" for the agent and "esame chicken" for the customer, and "-19" / "82, 15 minutes" for
            // the price. A token with no leading whitespace continues the previous one, so it inherits
            // that speaker rather than starting a new turn.
            if ($assigned !== [] && !$this->startsNewWord($text)) {
                $previous = $assigned[count($assigned) - 1];

                if ($previous['speaker'] !== SpeakerRole::UNKNOWN->value) {
                    $speaker = $previous['speaker'];
                }
            }

            ++$attributedTokens;
            $attributedDuration += $span;

            if ($bridged) {
                ++$bridgedTokens;
                $bridgedDuration += $span;
            }

            $assigned[] = [
                'token' => new TranscriptToken($token->startMs, $token->endMs, $text),
                'speaker' => $speaker,
                // A bridged token is fully inside one speaker's turn as far as anyone can tell — the
                // ambiguity is about *which* turn, not about how much of it. Scoring it 1.0 keeps the
                // ratio meaning "how cleanly this sits inside the turn it was given".
                'ratio' => $bridged ? 1.0 : min(1.0, $overlap / $span),
                'bridged' => $bridged,
            ];
        }

        $quality = new AlignmentQuality(
            $totalDuration > 0 ? $attributedDuration / $totalDuration : 0.0,
            $totalDuration > 0 ? $bridgedDuration / $totalDuration : 0.0,
            $this->overlappingShare($segments),
            count($assigned),
            $attributedTokens,
            $bridgedTokens,
        );

        return new AlignedTranscript($this->coalesce($assigned), $quality);
    }

    /**
     * @param list<SpeakerSegment> $segments
     *
     * @return array{0: string|null, 1: int} speaker and overlapping milliseconds
     */
    private function bestOverlap(TranscriptToken $token, array $segments): array
    {
        $best = null;
        $bestOverlap = 0;

        foreach ($segments as $segment) {
            $overlap = $token->overlapWith($segment->startMs, $segment->endMs);

            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $segment->speaker;
            }
        }

        return [$best, $bestOverlap];
    }

    /**
     * Resolves a token that sits between two detected speech regions.
     *
     * Two cases, and only the second needs a tolerance:
     *
     * 1. **The same speaker is talking on both sides.** The token is inside one person's turn, in a
     *    pause they took. There is nothing ambiguous about it, so it is attributed regardless of how
     *    long the pause was.
     * 2. **Different speakers, or only one side exists.** The token's midpoint decides which turn it
     *    belongs to, and the answer is accepted only if that edge is within the tolerance. Beyond it
     *    the token is genuinely stranded — a long silence, music, or speech the diarizer rejected —
     *    and is left unattributed rather than guessed at.
     *
     * The midpoint rather than the token's edges is deliberate: a token straddling a turn change should
     * follow where most of it lies, not which boundary it happens to touch first.
     *
     * @param list<SpeakerSegment> $segments ordered by start
     */
    private function bridgeGap(TranscriptToken $token, array $segments, int $toleranceMs): ?string
    {
        $midpoint = (int) (($token->startMs + $token->endMs) / 2);

        $before = null;
        $after = null;

        foreach ($segments as $segment) {
            if ($segment->endMs <= $midpoint && ($before === null || $segment->endMs > $before->endMs)) {
                $before = $segment;
            }

            if ($segment->startMs >= $midpoint && ($after === null || $segment->startMs < $after->startMs)) {
                $after = $segment;
            }
        }

        if ($before !== null && $after !== null && $before->speaker === $after->speaker) {
            return $before->speaker;
        }

        $nearest = null;
        $nearestDistance = null;

        if ($before !== null) {
            $nearest = $before->speaker;
            $nearestDistance = $midpoint - $before->endMs;
        }

        if ($after !== null && ($nearestDistance === null || ($after->startMs - $midpoint) < $nearestDistance)) {
            $nearest = $after->speaker;
            $nearestDistance = $after->startMs - $midpoint;
        }

        if ($nearest === null || $nearestDistance === null || $nearestDistance > $toleranceMs) {
            return null;
        }

        return $nearest;
    }

    /**
     * Share of the recording where two *different* speakers' intervals genuinely overlap.
     *
     * The only honest measure of simultaneous speech. On the reference call it was exactly zero, which
     * is why the old "heavy overlapping speech" message was wrong.
     *
     * @param list<SpeakerSegment> $segments ordered by start
     */
    private function overlappingShare(array $segments): float
    {
        $span = 0;
        foreach ($segments as $segment) {
            $span = max($span, $segment->endMs);
        }

        if ($span <= 0) {
            return 0.0;
        }

        $overlap = 0;
        $count = count($segments);

        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($segments[$i]->speaker === $segments[$j]->speaker) {
                    continue;
                }

                $shared = min($segments[$i]->endMs, $segments[$j]->endMs)
                    - max($segments[$i]->startMs, $segments[$j]->startMs);

                if ($shared > 0) {
                    $overlap += $shared;
                }
            }
        }

        return min(1.0, $overlap / $span);
    }

    /**
     * @param list<array{token: TranscriptToken, speaker: string, ratio: float, bridged: bool}> $assigned
     *
     * @return list<SpeakerUtterance>
     */
    private function coalesce(array $assigned): array
    {
        $utterances = [];

        $currentSpeaker = null;
        $currentText = '';
        $currentStart = 0;
        $currentEnd = 0;
        $ratioSum = 0.0;
        $ratioCount = 0;

        foreach ($assigned as $entry) {
            $speaker = $entry['speaker'];
            $token = $entry['token'];

            if ($speaker !== $currentSpeaker) {
                if ($currentSpeaker !== null && trim($currentText) !== '') {
                    $utterances[] = new SpeakerUtterance(
                        $currentStart,
                        $currentEnd,
                        $currentSpeaker,
                        SpeakerRole::UNKNOWN,
                        trim($currentText),
                        $ratioCount > 0 ? $ratioSum / $ratioCount : 0.0,
                    );
                }

                $currentSpeaker = $speaker;
                $currentText = '';
                $currentStart = $token->startMs;
                $ratioSum = 0.0;
                $ratioCount = 0;
            }

            // Whisper tokens carry their own leading spaces; concatenating verbatim is what preserves
            // the original wording, including the spacing inside numbers and addresses.
            $currentText .= $token->text;
            $currentEnd = max($currentEnd, $token->endMs);
            $ratioSum += $entry['ratio'];
            ++$ratioCount;
        }

        if ($currentSpeaker !== null && trim($currentText) !== '') {
            $utterances[] = new SpeakerUtterance(
                $currentStart,
                $currentEnd,
                $currentSpeaker,
                SpeakerRole::UNKNOWN,
                trim($currentText),
                $ratioCount > 0 ? $ratioSum / $ratioCount : 0.0,
            );
        }

        return $utterances;
    }

    /**
     * Whether this token begins a new word rather than continuing the previous one.
     *
     * Whisper prefixes a word-initial token with a space; continuations and trailing punctuation have
     * none. That is the only signal available, and it is reliable because it is how the tokenizer
     * encodes word boundaries in the first place.
     */
    private function startsNewWord(string $text): bool
    {
        return $text === '' || preg_match('/^\s/u', $text) === 1;
    }

    /**
     * Strips whisper's control tokens, which are markers rather than speech.
     *
     * The pattern must not require a trailing underscore: whisper emits both `[_BEG_]` and timestamp
     * markers like `[_TT_390]`. An earlier version only matched the first form, so nine `[_TT_nnn]`
     * markers leaked into the aligned text of the reference call.
     *
     * Only these are removed. No rewriting, no punctuation normalisation, no case folding — the stored
     * role columns have to contain what was said, not a tidied paraphrase of it.
     */
    private function cleanTokenText(string $text): string
    {
        $cleaned = (string) preg_replace('/\[_[A-Z0-9_]+\]/', '', $text);

        return trim($cleaned) === '' ? '' : $cleaned;
    }
}
