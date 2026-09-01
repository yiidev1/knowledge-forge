<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function mb_substr;
use function min;
use function trim;

/**
 * One turn of a reviewed conversation.
 *
 * The stored shape is `speaker_segments`' six keys plus two optional markers, emitted **only when
 * true** so a turn nobody has split or reworded serialises byte-identically to a machine-written one.
 * The existing {@see \App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder} reads only those six
 * keys, so it consumes reviewed JSON without knowing this class exists.
 *
 * - `approx` — the boundary was made by an administrator splitting a turn, so the timestamps are the
 *   parent's rather than anything observed. Token-level times are not persisted, so there is nothing
 *   more precise to record and nothing may be invented in its place.
 * - `edited` — the wording was corrected. The machine's own transcription is untouched in `transcript`.
 */
final readonly class ReviewedTurn
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $speaker,
        public SpeakerRole $role,
        public string $text,
        public float $confidence,
        public bool $approx = false,
        public bool $edited = false,
    ) {}

    public static function fromUtterance(SpeakerUtterance $utterance): self
    {
        return new self(
            $utterance->startMs,
            $utterance->endMs,
            $utterance->speaker,
            $utterance->role,
            $utterance->text,
            $utterance->confidence,
        );
    }

    /**
     * Decoded JSON, so the key type is whatever was in the file rather than anything guaranteed.
     * Every read below is guarded, and an unrecognisable row returns null rather than a half-built turn.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $text = $row['text'] ?? null;
        $speaker = $row['speaker'] ?? null;

        if (!is_string($text) || !is_string($speaker)) {
            return null;
        }

        $startMs = $row['start_ms'] ?? null;
        $endMs = $row['end_ms'] ?? null;
        $confidence = $row['confidence'] ?? null;

        return new self(
            is_int($startMs) ? $startMs : 0,
            is_int($endMs) ? $endMs : 0,
            $speaker,
            SpeakerRole::fromStorage(is_string($row['role'] ?? null) ? (string) $row['role'] : null),
            $text,
            is_numeric($confidence) ? (float) $confidence : 0.0,
            is_bool($row['approx'] ?? null) && $row['approx'],
            is_bool($row['edited'] ?? null) && $row['edited'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'speaker' => $this->speaker,
            'role' => $this->role->value,
            'text' => $this->text,
            'confidence' => $this->confidence,
        ];

        // Omitted when false so an untouched turn is indistinguishable from the machine's own row.
        if ($this->approx) {
            $row['approx'] = true;
        }

        if ($this->edited) {
            $row['edited'] = true;
        }

        return $row;
    }

    public function withRole(SpeakerRole $role): self
    {
        return new self(
            $this->startMs,
            $this->endMs,
            $this->speaker,
            $role,
            $this->text,
            $this->confidence,
            $this->approx,
            $this->edited,
        );
    }

    /** Wording corrected. Timestamps untouched — the words changed, not when they were said. */
    public function withText(string $text): self
    {
        return new self(
            $this->startMs,
            $this->endMs,
            $this->speaker,
            $this->role,
            $text,
            $this->confidence,
            $this->approx,
            true,
        );
    }

    /**
     * Cut in two at a character offset.
     *
     * **Both halves keep this turn's full range**, and both are marked approximate. There is no token
     * timing to divide, and splitting the span by character position would produce a number that looks
     * measured and is not — speech rate is not uniform, so the midpoint of the text is not the midpoint
     * of the sound.
     *
     * @return array{0: self, 1: self}
     */
    public function splitAt(int $offset): array
    {
        $before = trim(mb_substr($this->text, 0, $offset));
        $after = trim(mb_substr($this->text, $offset));

        return [
            new self($this->startMs, $this->endMs, $this->speaker, $this->role, $before, $this->confidence, true, $this->edited),
            new self($this->startMs, $this->endMs, $this->speaker, $this->role, $after, $this->confidence, true, $this->edited),
        ];
    }

    /**
     * Join with a turn that follows it.
     *
     * The span becomes `min(start)…max(end)`, which is exact for two adjacent turns — no approximation
     * is introduced here, though one is inherited if either side already carried it.
     */
    public function mergedWith(self $next): self
    {
        return new self(
            min($this->startMs, $next->startMs),
            max($this->endMs, $next->endMs),
            $this->speaker,
            $this->role,
            trim(trim($this->text) . ' ' . trim($next->text)),
            min($this->confidence, $next->confidence),
            $this->approx || $next->approx,
            $this->edited || $next->edited,
        );
    }
}
