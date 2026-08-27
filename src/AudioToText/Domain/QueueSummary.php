<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The counts shown above the upload form and the conversions list.
 *
 * ## The window is fixed, and deliberately no longer tied to retention
 *
 * These figures once took their window from `AUDIO_TRANSCRIPTION_RETENTION_SECONDS`, on the reasoning
 * that a terminal job is purged at roughly the moment it would drop out of the count anyway. That
 * coupling silently broke the page the day retention was set to `0` — which means *keep conversations
 * indefinitely*, not *count nothing*. Both callers computed `now() - 0 seconds`, so the cutoff became
 * the present instant and every completed job fell outside a window of zero width. The page reported
 * `COMPLETED (24H) 0` above a list of nineteen completed jobs.
 *
 * The lesson is that one setting was doing two unrelated jobs. How long recordings are kept is an
 * operator's decision; how much recent history this strip summarises is a property of the strip, and
 * is now stated here, once, as {@see WINDOW_HOURS}. The label is derived from the same constant, so
 * the two cannot disagree.
 *
 * Nothing here is infrastructural — no worker identity, no paths, no per-administrator breakdown.
 */
final readonly class QueueSummary
{
    /**
     * How much recent history the terminal counters cover.
     *
     * Independent of retention, of the diarization settings and of the queue cap. Changing it changes
     * both the numbers and the label together.
     */
    public const WINDOW_HOURS = 24;

    public function __construct(
        public int $queued,
        public int $processing,
        public int $completedLast24h,
        public int $failedLast24h,
    ) {}

    public function hasActive(): bool
    {
        return $this->queued > 0 || $this->processing > 0;
    }

    /** "24h" — for the two terminal counters' labels, so they always match the window they report. */
    public static function windowLabel(): string
    {
        return self::WINDOW_HOURS . 'h';
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0);
    }
}
