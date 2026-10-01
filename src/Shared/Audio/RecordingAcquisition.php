<?php

declare(strict_types=1);

namespace App\Shared\Audio;

use function array_filter;
use function count;
use function implode;
use function intdiv;
use function sprintf;

/**
 * One call's recordings on their way in, and what can honestly be said about them.
 *
 * ## Two numbers, because one of them would lie
 *
 * A call is up to three recordings and they finish separately, so "how far along is this?" and "what did
 * I actually get?" are different questions with different answers. Reporting either one alone misleads:
 *
 * - **The bar reports channels checked** — how much of the asking is done. It reaches full when the
 *   provider has answered for every channel, whatever those answers were. That is what stops a merchant
 *   who only records the mixed call from staring at a third-full bar for ever on a call that finished
 *   perfectly.
 * - **The text reports what is there** — "1 recording available · 2 unavailable". Full-and-silent would
 *   read as three recordings downloaded, which would be a plain untruth.
 *
 * So the bar never claims recordings exist; it claims the work is done. The sentence beside it is what
 * says how many there are, and the two are always rendered together.
 *
 * ## No byte percentages
 *
 * Nothing upstream reports bytes-of-total — the downloader streams and counts, but the provider sends no
 * length it could be measured against. So the only honest unit of progress is a whole channel, and every
 * figure here is a count of channels. A number like 42% does not appear anywhere because there is
 * nothing that could produce it truthfully.
 */
final readonly class RecordingAcquisition
{
    /**
     * @param non-empty-array<string, RecordingAcquisitionState> $channels storage channel key => state,
     *                                                                    in the order they are shown
     */
    public function __construct(
        public string $callSessionId,
        public ?string $orderId,
        public ?string $callTimeRaw,
        public array $channels,
    ) {}

    /** How many channels were asked for. Three for an ordinary call. */
    public function total(): int
    {
        return count($this->channels);
    }

    /** How many the provider has answered for, whatever the answer. This is what the bar reports. */
    public function checked(): int
    {
        return count(array_filter($this->channels, static fn(RecordingAcquisitionState $s): bool => $s->isSettled()));
    }

    /** How many recordings are actually here. This is what the text reports. */
    public function available(): int
    {
        return count(array_filter($this->channels, static fn(RecordingAcquisitionState $s): bool => $s->isAvailable()));
    }

    public function unavailable(): int
    {
        return $this->countOf(RecordingAcquisitionState::Unavailable);
    }

    public function failed(): int
    {
        return $this->countOf(RecordingAcquisitionState::Failed);
    }

    /** Whether anything is still expected to happen. What decides if a page keeps polling. */
    public function isActive(): bool
    {
        return $this->checked() < $this->total();
    }

    /**
     * Channels checked, as a whole percent — the bar, and nothing else.
     *
     * Integer division on purpose: 1 of 3 is 33, not 33.33. A figure with decimals would suggest a
     * precision this is not measuring, and the authoritative statement is the text beside it anyway.
     */
    public function percentChecked(): int
    {
        $total = $this->total();

        return $total === 0 ? 0 : intdiv($this->checked() * 100, $total);
    }

    /**
     * What the bar is counting, said in words, so the figure is never on its own.
     *
     * "2 of 3 recordings checked" while it runs — never "ready", because a checked channel may have
     * turned out not to exist.
     */
    public function progressText(): string
    {
        return sprintf('%d of %d recordings checked', $this->checked(), $this->total());
    }

    /**
     * What is actually there, which is the sentence that matters once the asking is done.
     *
     * Reads "1 recording available · 2 unavailable" — availability first, because it is what the reader
     * came for, and the shortfall named rather than left to be inferred from a number that stops short.
     */
    public function availabilityText(): string
    {
        $parts = [sprintf(
            '%d %s available',
            $this->available(),
            $this->available() === 1 ? 'recording' : 'recordings',
        )];

        if ($this->unavailable() > 0) {
            $parts[] = sprintf('%d unavailable', $this->unavailable());
        }

        if ($this->failed() > 0) {
            $parts[] = sprintf('%d failed', $this->failed());
        }

        return implode(' · ', $parts);
    }

    /**
     * One word for the whole call.
     *
     * Order matters. Anything still moving outranks every settled answer, because a final word about a
     * call whose caller channel is still being fetched would be wrong before it was printed. After that
     * the question is only what arrived.
     */
    public function outcome(): RecordingAcquisitionOutcome
    {
        if ($this->isActive()) {
            // Nothing claimed yet reads as waiting rather than downloading: the distinction is the whole
            // of what an operator can see in the first minute after pressing the button.
            return $this->countOf(RecordingAcquisitionState::Downloading) > 0
                ? RecordingAcquisitionOutcome::Downloading
                : RecordingAcquisitionOutcome::Waiting;
        }

        return match (true) {
            $this->available() === $this->total() => RecordingAcquisitionOutcome::Downloaded,
            $this->available() > 0 => RecordingAcquisitionOutcome::Partial,
            // Nothing arrived. Whether that is a failure depends entirely on why.
            $this->failed() === 0 => RecordingAcquisitionOutcome::Unavailable,
            default => RecordingAcquisitionOutcome::Failed,
        };
    }

    /**
     * What is happening right now, for the line under the bar.
     *
     * Null when nothing is: a finished acquisition has no current anything, and the panel it would sit
     * in is not drawn at all.
     */
    public function currentStep(): ?string
    {
        foreach ($this->channels as $channel => $state) {
            if ($state === RecordingAcquisitionState::Downloading) {
                return 'Downloading the ' . $channel . ' recording';
            }
        }

        // Asked for, nothing claimed yet. True for the minute or so before the scheduled run picks it
        // up, and saying so beats an empty line that reads as nothing happening.
        return $this->isActive() ? 'Waiting for the next recording to start' : null;
    }

    /** Whether any single channel could usefully be asked for again. */
    public function hasRetryableChannel(): bool
    {
        foreach ($this->channels as $state) {
            if ($state->worthRetrying()) {
                return true;
            }
        }

        return false;
    }

    private function countOf(RecordingAcquisitionState $state): int
    {
        return count(array_filter(
            $this->channels,
            static fn(RecordingAcquisitionState $s): bool => $s === $state,
        ));
    }
}
