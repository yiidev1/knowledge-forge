<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Diarization;

use App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface;

/**
 * The diarizer used when speaker separation is switched off.
 *
 * A real object rather than a null check scattered through the worker. The pipeline runs end to end
 * either way, the stage reports NOT_SUPPORTED, and turning the feature on later is a configuration
 * change rather than a different code path that has never been exercised.
 */
final readonly class NullSpeakerDiarizer implements SpeakerDiarizerInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function method(): string
    {
        return 'none';
    }

    /**
     * @return list<never>
     */
    public function diarize(string $wavPath): array
    {
        return [];
    }
}
