<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

/**
 * Speaker-separation tunables.
 *
 * Ships disabled. The transcription pipeline is complete and useful without diarization, and a feature
 * that hard-depends on a model nobody has installed yet is a feature that cannot be deployed. With
 * `enabled = false` every job still transcribes and simply records NOT_SUPPORTED for the split.
 */
final readonly class DiarizationSettings
{
    public function __construct(
        public bool $enabled,
        public string $binary,
        public string $segmentationModel,
        public string $embeddingModel,
        public int $timeoutSeconds,
        public float $minConfidence,
        public int $maxSpeakers,
        /**
         * Gap tolerance for token attribution, in milliseconds. See the SPEC entry in
         * {@see \App\Environment} for the measurements behind the default.
         */
        public int $boundaryToleranceMs,
    ) {}
}
