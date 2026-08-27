<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Fine-grained telemetry for what the worker is doing inside a {@see JobStatus::PROCESSING} job.
 *
 * Purely additive: `status` keeps its four values and every transition rule it always had, and nothing
 * authorizes, claims or expires on a stage. That separation is what lets the pipeline grow a step
 * without touching the queue's contract.
 *
 * `DIARIZING` and `MAPPING_SPEAKERS` are skipped when speaker separation is disabled or unavailable,
 * so a stage sequence is not guaranteed to contain every case.
 */
enum ProcessingStage: string
{
    case QUEUED = 'QUEUED';
    case CLAIMED = 'CLAIMED';
    case CONVERTING = 'CONVERTING';
    case TRANSCRIBING = 'TRANSCRIBING';
    case DIARIZING = 'DIARIZING';
    case MAPPING_SPEAKERS = 'MAPPING_SPEAKERS';
    case SAVING = 'SAVING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Waiting for the worker',
            self::CLAIMED => 'Starting',
            self::CONVERTING => 'Converting audio',
            self::TRANSCRIBING => 'Transcribing audio',
            self::DIARIZING => 'Separating speakers',
            self::MAPPING_SPEAKERS => 'Identifying agent and customer',
            self::SAVING => 'Saving results',
            self::COMPLETED => 'Done',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Stages are optional in storage: the column is nullable so the migration is additive, and a row
     * written by an older worker simply has no stage to show.
     */
    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
