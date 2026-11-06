<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

use App\Ai\OpenAi\Client\SleeperInterface;
use App\Order58\Domain\CallRecordings\RecordingDurationRepositoryInterface;
use App\Order58\Domain\CallRecordings\RecordingLocation;
use App\Shared\Domain\Clock\ClockInterface;
use DateInterval;

use function hrtime;
use function min;

/**
 * Each Case Order call recording's length: measured once, by this server, and kept.
 *
 * ## The one trusted source
 *
 * {@see RecordingStreamer::measure()}: a HEAD request to the provider, the declared size turned into
 * seconds by {@see RecordingMeasurement}. That is the number the page has always printed, measured where
 * the provider's allowlist applies. Nothing a browser reports is ever stored — the page's own fallback of
 * reading a WAV header in the browser stays a display-only reading.
 *
 * ## Two ways in, one store
 *
 *  - {@see forLocation()} — the page's duration request. A stored length is answered straight from the
 *    database, so a page visit no longer reaches the provider at all once a recording is measured; an
 *    unmeasured one is measured as before and the answer stored on the way out.
 *  - {@see runBatch()} — the background backfill (`kf:order58:call-recordings:measure-durations`): a
 *    bounded batch, one request at a time, a pause between requests, a time budget, a claim per row so
 *    two runs never measure the same recording, and exponential backoff for failures.
 *
 * Either way the stored value is the recording's length, not a verdict, so changing the duration rule's
 * formula never requires measuring anything again.
 */
final readonly class RecordingDurations
{
    /** How long a run holds a claimed row: comfortably past one HEAD request's own deadline. */
    private const CLAIM_MINUTES = 10;

    /** Retries never wait longer than a day. */
    private const MAX_RETRY_MINUTES = 1440;

    public function __construct(
        private RecordingStreamer $streamer,
        private RecordingDurationRepositoryInterface $durations,
        private ClockInterface $clock,
        private SleeperInterface $sleeper,
        private RecordingDurationSettings $settings = new RecordingDurationSettings(),
    ) {}

    /** The recording's length for the page: stored if known, otherwise measured now and stored. */
    public function forLocation(RecordingLocation $location): ?float
    {
        if ($location->durationSeconds !== null) {
            return $location->durationSeconds;
        }

        $measured = $this->streamer->measure($location->url);

        if ($measured->seconds === null) {
            return null;
        }

        $this->durations->record($location->recordingId, $measured->seconds, $this->clock->now());

        return $measured->seconds;
    }

    /** One background run over the unmeasured recordings. `$limit` overrides the configured batch. */
    public function runBatch(?int $limit = null): RecordingDurationRun
    {
        $limit = $limit ?? $this->settings->batch;
        $deadline = hrtime(true) + $this->settings->runSeconds * 1_000_000_000;
        $run = RecordingDurationRun::start();

        foreach ($this->durations->due($limit, $this->settings->maxAttempts, $this->clock->now()) as $index => $recording) {
            if (hrtime(true) >= $deadline) {
                return $run->stopped('time budget used');
            }

            // A steady trickle rather than a burst: the provider also serves everyone who presses Play.
            if ($index > 0 && $this->settings->pauseMs > 0) {
                $this->sleeper->sleep($this->settings->pauseMs / 1000);
            }

            $now = $this->clock->now();

            if (!$this->durations->claim($recording->recordingId, $now, $now->add(new DateInterval('PT' . self::CLAIM_MINUTES . 'M')))) {
                $run = $run->skipped();

                continue;
            }

            $measured = $this->streamer->measure($recording->url);

            if ($measured->seconds !== null) {
                $run = $this->durations->record($recording->recordingId, $measured->seconds, $this->clock->now())
                    ? $run->measured()
                    : $run->skipped();

                continue;
            }

            $failure = $measured->failure;

            // Every playback slot is taken by people listening. Not the recording's fault: release it
            // at once without using a retry, and end the run — the next one will find the slots free.
            if ($failure === RecordingFetchFailure::Busy) {
                $this->durations->failed($recording->recordingId, $failure->value, $this->clock->now(), false);

                return $run->stopped('playback slots busy');
            }

            // Retrying cannot change these: an address this server refuses to call, a file too large to
            // be a call, or a size that does not describe a playable recording.
            if ($failure === null || $failure === RecordingFetchFailure::Refused || $failure === RecordingFetchFailure::TooLarge) {
                $this->durations->abandon($recording->recordingId, $failure?->value ?? 'unmeasurable', $this->settings->maxAttempts);
                $run = $run->abandoned();

                continue;
            }

            $attempt = $recording->attempts + 1;

            if ($attempt >= $this->settings->maxAttempts) {
                $this->durations->abandon($recording->recordingId, $failure->value, $this->settings->maxAttempts);
                $run = $run->abandoned();

                continue;
            }

            $this->durations->failed(
                $recording->recordingId,
                $failure->value,
                $this->clock->now()->add(new DateInterval('PT' . $this->retryMinutes($attempt) . 'M')),
                true,
            );
            $run = $run->failed();
        }

        return $run;
    }

    /** Exponential backoff: the configured delay after the first failure, doubling, capped at a day. */
    public function retryMinutes(int $attempt): int
    {
        $minutes = $this->settings->retryMinutes;

        for ($i = 1; $i < $attempt && $minutes < self::MAX_RETRY_MINUTES; ++$i) {
            $minutes *= 2;
        }

        return min($minutes, self::MAX_RETRY_MINUTES);
    }
}
