<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function max;
use function round;

/**
 * Roughly how long this recording will take, when there is enough to say so honestly.
 *
 * ## Why a range and never a countdown
 *
 * The figures below are ratios of wall-clock time to audio length, measured over completed jobs on a
 * real database rather than guessed:
 *
 * | provider | jobs | wall clock per audio second | observed spread |
 * |---|---|---|---|
 * | Deepgram | 26 | 0.26x | 9–45 s |
 * | Whisper  | 18 | 1.33x | 54–336 s |
 *
 * The spread is the point. Whisper's slowest run took six times its fastest, because the machine is
 * shared and the admission guard defers a tick whenever memory or load says so. A single number would
 * therefore be wrong most of the time, and a **countdown** built from one would be wrong *and* appear
 * to be measuring something — the failure mode where a bar sits at "30 seconds remaining" for four
 * minutes. So this publishes two bounds, the screen says "usually about", and nothing decrements.
 *
 * ## The queue wait is included
 *
 * A recording is not picked up the instant it is queued: a worker runs on a timer, so there is a wait
 * of up to roughly one tick before anything starts. Leaving that out would produce an estimate that is
 * honest about the part the user cannot see and wrong about the part they can.
 *
 * ## When there is nothing to say
 *
 * No duration probed, or no provider recorded, means no estimate — {@see forJob()} returns null and the
 * screen falls back to "This may take a few minutes" with an elapsed timer. That is the truthful answer,
 * and it is better than an estimate derived from a duration nobody measured.
 *
 * Nothing here is a promise, nothing is enforced against it, and no timeout is derived from it. It is a
 * sentence for a person who is waiting.
 */
final readonly class ProcessingEstimate
{
    /**
     * Wall clock per second of audio, low and high, per provider.
     *
     * Deliberately wider than the measured mean in both directions: the sample is a few dozen jobs on
     * one machine, and an estimate that is occasionally too generous costs nothing, while one that is
     * routinely beaten makes every later estimate unbelievable.
     */
    private const RATIOS = [
        'DEEPGRAM' => [0.15, 0.60],
        'WHISPER' => [0.90, 2.20],
    ];

    /** Roughly one worker tick, for the wait before anything starts. Not read from the timer — see the class docblock. */
    private const QUEUE_WAIT_SECONDS = 75;

    private function __construct(
        public int $lowSeconds,
        public int $highSeconds,
    ) {}

    public static function forJob(TranscriptionJob $job): ?self
    {
        $duration = $job->durationSeconds;

        if ($duration === null || $duration <= 0.0) {
            return null;
        }

        // The stored value, through the job's own accessor — which falls back to Whisper for a row
        // written before the column existed, and never consults the current default. An estimate must
        // describe the engine this job will actually run on.
        $ratios = self::RATIOS[$job->transcriptionProvider()->value] ?? null;

        if ($ratios === null) {
            return null;
        }

        [$low, $high] = $ratios;

        return new self(
            // A floor of half a minute: a four-second clip does not take one second end to end, and an
            // estimate under it reads as broken rather than as fast.
            max(30, (int) round($duration * $low) + self::QUEUE_WAIT_SECONDS),
            max(60, (int) round($duration * $high) + self::QUEUE_WAIT_SECONDS),
        );
    }

    /** @return array{lowSeconds: int, highSeconds: int} */
    public function toArray(): array
    {
        return ['lowSeconds' => $this->lowSeconds, 'highSeconds' => $this->highSeconds];
    }
}
