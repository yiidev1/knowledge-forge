<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function floor;
use function intdiv;
use function max;
use function number_format;
use function sprintf;

/**
 * When a turn happened, and how long the other person took to start it.
 *
 * Every method returns `null` when there is nothing honest to show, so the template renders a label or
 * renders nothing and never has to decide which. Deciding in a template is how "0.0s response" ends up
 * on screen next to a boundary that was never measured.
 *
 * Nothing here is persisted. The delay is arithmetic over two integers already in `speaker_segments`,
 * and storing it would create a second copy to keep in step with any later correction to the turns.
 */
final readonly class TurnTiming
{
    /**
     * Below this, a gap is an artifact of how the transcript was cut, not a measurement of anyone.
     *
     * The aligner coalesces contiguous tokens, so a boundary drawn inside continuous speech produces a
     * gap of exactly zero. On the reference recording that happens on roughly a third of all turns —
     * printing "0.0s response" there would present the seam between two segments as a human reacting
     * instantly. 150 ms is comfortably below a real conversational turnaround and comfortably above the
     * rounding noise.
     */
    public const MIN_REPORTABLE_DELAY_MS = 150;

    private function __construct(
        public ?int $startMs,
        public ?int $endMs,
        public ?int $delayMs,
        public bool $overlapping,
        /**
         * Whether this span was inherited rather than observed.
         *
         * True only for the halves of an administrator's split. Token timestamps are not persisted, so
         * there is no defensible time for a boundary drawn inside a turn; both halves keep the parent's
         * full range and say so. Interpolating by character position would produce a number that looks
         * measured and is not, because nobody speaks at a constant rate.
         */
        public bool $approximate = false,
        /** Whether the previous turn already printed this exact span, so repeating it would mislead. */
        public bool $rangeRepeated = false,
    ) {}

    /** A turn whose stored timestamps are unusable: no range, no delay, nothing shown. */
    public static function untimed(): self
    {
        return new self(null, null, null, false);
    }

    public static function at(int $startMs, int $endMs, bool $approximate = false): self
    {
        return new self($startMs, $endMs, null, false, $approximate);
    }

    /** The other speaker finished, then this turn began after `$delayMs`. */
    public function respondingAfter(int $delayMs): self
    {
        return new self($this->startMs, $this->endMs, max(0, $delayMs), false, $this->approximate);
    }

    /** This turn began before the other speaker had finished. */
    public function overlappingPrevious(): self
    {
        return new self($this->startMs, $this->endMs, 0, true, $this->approximate);
    }

    /**
     * The turn before this one covers the identical span, so this one shows no range of its own.
     *
     * Two adjacent bubbles both stamped 02:14–02:21 would read as two separately observed measurements
     * that happen to coincide. They are one measurement, split by hand.
     */
    public function repeatingPreviousRange(): self
    {
        return new self($this->startMs, $this->endMs, $this->delayMs, $this->overlapping, $this->approximate, true);
    }

    public function hasTimestamps(): bool
    {
        return $this->startMs !== null && $this->endMs !== null;
    }

    /**
     * "02:14–02:21", "~02:14–02:21" for an inherited span, or null when there is nothing to show.
     *
     * The tilde is the whole point: it distinguishes a range the machine measured from one a person's
     * split produced, without which the two are indistinguishable on screen.
     */
    public function rangeLabel(): ?string
    {
        if ($this->startMs === null || $this->endMs === null || $this->rangeRepeated) {
            return null;
        }

        $label = self::clock($this->startMs) . '–' . self::clock($this->endMs);

        return $this->approximate ? '~' . $label : $label;
    }

    /**
     * "1.4s response", "overlapping", or null.
     *
     * Null covers three different situations that all mean the same thing to a reader — the first turn,
     * a continuation by the same speaker, and a gap too small to be real — so none of them is dressed
     * up as a measurement.
     */
    public function delayLabel(): ?string
    {
        if ($this->overlapping) {
            return 'overlapping';
        }

        if ($this->delayMs === null || $this->delayMs < self::MIN_REPORTABLE_DELAY_MS) {
            return null;
        }

        return number_format($this->delayMs / 1000, 1) . 's response';
    }

    private static function clock(int $milliseconds): string
    {
        $seconds = (int) floor(max(0, $milliseconds) / 1000);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
