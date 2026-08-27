<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\Speaker\AlignmentQuality;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;

/**
 * What the aligner produced: the utterances, and an honest account of how well they lined up.
 *
 * The quality figures travel with the result rather than being recomputed by the caller, so the
 * decision to publish or hold back a split is made from the same numbers that produced it.
 */
final readonly class AlignedTranscript
{
    /**
     * @param list<SpeakerUtterance> $utterances chronological
     */
    public function __construct(
        public array $utterances,
        public AlignmentQuality $quality,
    ) {}

    public static function empty(): self
    {
        return new self([], AlignmentQuality::empty());
    }
}
