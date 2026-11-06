<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/** One stored Case Order recording whose duration has not been measured yet. */
final readonly class RecordingToMeasure
{
    public function __construct(
        public int $recordingId,
        /** The provider's address as stored. Never logged or printed: it holds a phone number. */
        public string $url,
        public int $attempts,
    ) {}
}
