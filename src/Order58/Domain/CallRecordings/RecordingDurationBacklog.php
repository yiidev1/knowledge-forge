<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/** Where the duration measurements stand. */
final readonly class RecordingDurationBacklog
{
    public function __construct(
        public int $measured,
        /** Unmeasured and still to be tried. */
        public int $pending,
        /** Unmeasured, and every retry used; left alone until reset. */
        public int $exhausted,
    ) {}
}
