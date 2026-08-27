<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function sprintf;

/**
 * One interval of speech attributed to a neutral speaker cluster by the diarizer.
 *
 * Neutral on purpose. The diarizer knows that two voices are different; it has no idea which one takes
 * orders for a living. Naming these `SPEAKER_00` / `SPEAKER_01` keeps that boundary visible, so nothing
 * downstream can quietly assume the first voice is the agent.
 */
final readonly class SpeakerSegment
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $speaker,
    ) {}

    /** Builds the conventional neutral label from a diarizer's zero-based cluster index. */
    public static function labelFor(int $clusterIndex): string
    {
        return sprintf('SPEAKER_%02d', max(0, $clusterIndex));
    }

    public function durationMs(): int
    {
        return max(0, $this->endMs - $this->startMs);
    }
}
