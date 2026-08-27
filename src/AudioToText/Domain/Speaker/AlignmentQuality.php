<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function implode;
use function sprintf;

/**
 * How well the transcript could be matched to the diarizer's speaker turns.
 *
 * Measured **by token duration, not by counting utterances**, and that distinction is what this class
 * exists to fix. Counting treats a one-word "Yes." exactly like a nine-second sentence, so a handful of
 * short interjections at turn boundaries can outvote the bulk of the conversation. On the reference
 * call the two metrics disagreed sharply: 51% of *utterances* were unattributed, but only 30% of the
 * *speech*, and the difference was entirely short fragments sitting in pauses.
 *
 * `unattributed` and `overlapping` are also kept apart, because they mean opposite things. A token
 * matching no speaker turn is usually sitting in a **gap** — silence the diarizer did not mark — while
 * genuine simultaneous speech shows up as diarization intervals that overlap each other. Reporting the
 * first as the second, which this code used to do, sends whoever reads it looking for a problem that
 * is not there.
 */
final readonly class AlignmentQuality
{
    /**
     * @param float $attributedShare  share of token duration assigned to a speaker, 0.0–1.0
     * @param float $bridgedShare     share assigned by bridging a diarization gap rather than by direct
     *                                overlap — high values mean sparse diarization, not bad audio
     * @param float $overlappingShare share of audio where two different speakers' turns genuinely
     *                                overlap; the only honest measure of simultaneous speech
     */
    public function __construct(
        public float $attributedShare,
        public float $bridgedShare,
        public float $overlappingShare,
        public int $totalTokens,
        public int $attributedTokens,
        public int $bridgedTokens,
    ) {}

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, 0, 0, 0);
    }

    public function unattributedShare(): float
    {
        return 1.0 - $this->attributedShare;
    }

    /**
     * A description of what actually happened, for the log and for the review reason.
     *
     * Deliberately names the mechanism rather than guessing at a cause: "sat in gaps between detected
     * speech" is checkable against the diarization output, whereas "heavy overlapping speech" is an
     * inference — and on the reference call it was the wrong one.
     */
    public function describe(): string
    {
        if ($this->totalTokens === 0) {
            return 'no transcript tokens could be aligned';
        }

        $parts = [sprintf('%.0f%% of speech attributed to a speaker', $this->attributedShare * 100)];

        if ($this->unattributedShare() > 0.01) {
            $parts[] = sprintf(
                '%.0f%% sat in gaps between detected speech and could not be attributed',
                $this->unattributedShare() * 100,
            );
        }

        if ($this->bridgedShare > 0.01) {
            $parts[] = sprintf('%.0f%% attributed across a short pause', $this->bridgedShare * 100);
        }

        if ($this->overlappingShare > 0.01) {
            $parts[] = sprintf(
                '%.0f%% of the recording has two speakers talking at once',
                $this->overlappingShare * 100,
            );
        }

        return implode('; ', $parts);
    }
}
