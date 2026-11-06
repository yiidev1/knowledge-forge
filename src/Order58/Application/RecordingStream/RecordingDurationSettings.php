<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

/** The background duration measurement's limits; ORDER58_RECORDING_DURATION_* in `.env`. */
final readonly class RecordingDurationSettings
{
    public function __construct(
        /** Recordings measured per run at most. */
        public int $batch = 50,
        /** Wait between two requests to the provider, in milliseconds. */
        public int $pauseMs = 250,
        /** A run starts no new request after this many seconds. */
        public int $runSeconds = 50,
        /** Failed measurements are retried until this many attempts in all. */
        public int $maxAttempts = 5,
        /** First retry delay in minutes; each further one doubles, up to a day. */
        public int $retryMinutes = 15,
    ) {}
}
