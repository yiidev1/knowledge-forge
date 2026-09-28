<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use function max;

/**
 * One store's conversion count, split by the recording type each conversion was uploaded as.
 *
 * ## Total is the number it has always been
 *
 * `$total` counts rows in `audio_conversations` for the store — conversions, never transcription jobs.
 * A separate Customer + Agent upload is one conversion and two jobs, and the number an administrator
 * counts is the first. Adding this breakdown did not change what that number means, and it must not:
 * the same value drives the "Uploaded audio" filter.
 *
 * ## Why `other()` exists, and why it is not folded into Mixed
 *
 * `audio_conversations.recording_type` is **nullable** by design, so the three named types do not add
 * up to the total. Two entirely legitimate populations record no type at all:
 *
 *   1. A `SEPARATE` (Customer + Agent) upload. None of MIXED/CALLER/CALLEE describes a pair, and the
 *      conversation's `mode` already says what it is.
 *   2. A `COMMON` upload whose posted recording type was not one of the three allowed values. That is
 *      deliberately not an error — refusing the recording would let a stale open tab cost somebody
 *      their upload over a caption — so it is stored as a plain COMMON upload with no type.
 *
 * On top of those, every conversation created before the column existed is null.
 *
 * Counting those as Mixed would be a quiet lie: it would claim a channel the uploader never stated.
 * They are reported as `Other` instead, which is a fact rather than a guess.
 */
final readonly class StoreAudioBreakdown
{
    public function __construct(
        public int $total,
        public int $mixed,
        public int $caller,
        public int $callee,
    ) {}

    /** The empty case, so a caller can default without a null check. */
    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * Conversions carrying no recording type.
     *
     * Derived rather than selected: the four values then cannot disagree with each other, whatever a
     * future migration does to the column. Clamped at zero because a negative count would be a symptom
     * of a broken query rather than something a card should render.
     */
    public function other(): int
    {
        return max(0, $this->total - $this->mixed - $this->caller - $this->callee);
    }
}
