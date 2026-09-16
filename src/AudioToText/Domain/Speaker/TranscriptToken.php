<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * One timestamped piece of transcribed speech, from whichever engine produced it.
 *
 * Alignment happens at this granularity rather than at segment granularity, and that is not a
 * refinement — it is the difference between working and not working. Measured on a real 74-second
 * two-party call, whisper.cpp emitted five segments; one of them spanned 25.0s to 39.2s and contained
 * roughly eight speaker turns. Assigning that whole segment to one speaker would put both sides of the
 * conversation in the same column. Tokens are typically 100–500 ms, which is finer than a speaker turn.
 *
 * ## THE TEXT CONTRACT — a word-initial token MUST begin with whitespace
 *
 * `$text` carries its own spacing. A token that starts a new word begins with a space; a token that
 * continues the previous word, or that is trailing punctuation, does not. Joining tokens is therefore
 * plain concatenation, and the leading space is the **only** signal of a word boundary.
 *
 * Every engine must satisfy this, and they satisfy it differently:
 *
 *  - **whisper.cpp** emits it natively. Its tokenizer encodes word boundaries exactly this way, and it
 *    produces sub-word fragments ("-S", "esame") that genuinely do continue the previous word.
 *  - **Deepgram** returns bare whole words, so `DeepgramEngine` prepends the space. Every one of its
 *    tokens is word-initial, so that is a truthful representation rather than a workaround.
 *
 * ## Why this matters more than it looks
 *
 * {@see \App\AudioToText\Application\Speaker\SpeakerTranscriptAligner} reads it **twice**, and the
 * second use is easy to miss: it joins tokens into utterance text, and it decides whether a token
 * continues the previous word and must inherit its speaker — the rule that stops a turn boundary
 * splitting one word across two people. An engine that omits the space makes every token look like a
 * continuation, so a whole two-party conversation collapses into a single speaker whose text has run
 * together with no spaces.
 *
 * That is not hypothetical. It shipped, and it is why this contract is now written down here rather
 * than left implicit in the aligner. A regression test asserts each engine's compliance.
 */
final readonly class TranscriptToken
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {}

    public function durationMs(): int
    {
        return max(0, $this->endMs - $this->startMs);
    }

    /**
     * Overlap in milliseconds with an arbitrary interval.
     *
     * Zero-length tokens are common — whisper emits them for punctuation and for the segment-begin
     * marker — and would otherwise never overlap anything. They are treated as a 1 ms instant so that a
     * token sitting exactly on a boundary still lands in the interval that contains it.
     */
    public function overlapWith(int $startMs, int $endMs): int
    {
        $tokenEnd = $this->endMs > $this->startMs ? $this->endMs : $this->startMs + 1;

        return max(0, min($tokenEnd, $endMs) - max($this->startMs, $startMs));
    }
}
