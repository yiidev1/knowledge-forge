<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * One timestamped token from whisper.cpp's `-ojf` output.
 *
 * Alignment happens at this granularity rather than at segment granularity, and that is not a
 * refinement — it is the difference between working and not working. Measured on a real 74-second
 * two-party call, whisper.cpp emitted five segments; one of them spanned 25.0s to 39.2s and contained
 * roughly eight speaker turns. Assigning that whole segment to one speaker would put both sides of the
 * conversation in the same column. Tokens are typically 100–500 ms, which is finer than a speaker turn.
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
