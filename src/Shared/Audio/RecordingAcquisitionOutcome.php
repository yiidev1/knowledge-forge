<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * One word for a whole call's recordings, derived in {@see RecordingAcquisition::outcome()}.
 *
 * Six words rather than four, because the two extra ones are the difference between a reader trusting
 * this column and learning to ignore it:
 *
 * - {@see Waiting} is not {@see Downloading}. In the first minute after pressing the button nothing has
 *   been claimed yet, and saying "Downloading" then would be describing something that is not happening.
 * - {@see Unavailable} is not {@see Failed}. A merchant who records the mixed call and nothing else
 *   produces no caller and no callee on every call they ever make. Painting that red would make the
 *   column red on a healthy day, and a column that is red on healthy days is one nobody reads.
 */
enum RecordingAcquisitionOutcome: string
{
    /** Asked for; the provider has not been contacted yet. */
    case Waiting = 'WAITING';

    /** At least one channel is being fetched now. */
    case Downloading = 'DOWNLOADING';

    /** Every channel asked for is here. */
    case Downloaded = 'DOWNLOADED';

    /** Some audio arrived and some did not. The usual outcome for a single-channel merchant. */
    case Partial = 'PARTIAL';

    /** Settled, and the provider has none of it. Not a failure and not retryable. */
    case Unavailable = 'UNAVAILABLE';

    /** Nothing arrived, and at least part of why was a fault rather than an absence. */
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Pending download',
            self::Downloading => 'Downloading',
            self::Downloaded => 'Downloaded',
            self::Partial => 'Partial',
            self::Unavailable => 'Unavailable',
            self::Failed => 'Failed',
        };
    }

    /** From the palette `admin.css` defines: `success|error|warning|info|muted`. */
    public function badge(): string
    {
        return match ($this) {
            self::Downloaded => 'success',
            self::Downloading => 'info',
            self::Waiting => 'muted',
            self::Partial => 'warning',
            self::Unavailable => 'muted',
            self::Failed => 'error',
        };
    }

    /** Whether something is still expected to happen without anybody asking. Drives polling. */
    public function isActive(): bool
    {
        return $this === self::Waiting || $this === self::Downloading;
    }
}
