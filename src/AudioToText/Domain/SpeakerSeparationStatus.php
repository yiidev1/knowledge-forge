<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The outcome of speaker separation, tracked independently of {@see JobStatus}.
 *
 * The two are deliberately not merged. A transcription that succeeded is a useful result even when the
 * recording could not be split into agent and customer, and a wrong confident split is worse than an
 * honest one that asks to be reviewed. So a job can be `COMPLETED` with separation `NEEDS_REVIEW`, and
 * the full transcript is never discarded because this stage was inconclusive.
 */
enum SpeakerSeparationStatus: string
{
    /** Queued but not yet attempted. */
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    /** Two clusters, mapped to roles with sufficient confidence. */
    case COMPLETED = 'COMPLETED';
    /** Ran, but the result is not trustworthy enough to publish as agent/customer text. */
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    /** The diarizer errored, timed out or returned something unusable. */
    case FAILED = 'FAILED';
    /** Speaker separation is switched off, or the local toolchain is not installed. */
    case NOT_SUPPORTED = 'NOT_SUPPORTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'In progress',
            self::COMPLETED => 'Completed',
            self::NEEDS_REVIEW => 'Needs review',
            self::FAILED => 'Failed',
            self::NOT_SUPPORTED => 'Not supported',
        };
    }

    /** Whether agent/customer text may be shown as a finished result rather than a caveat. */
    public function isPublishable(): bool
    {
        return $this === self::COMPLETED;
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
