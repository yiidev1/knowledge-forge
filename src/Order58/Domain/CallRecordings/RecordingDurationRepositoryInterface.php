<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

use DateTimeImmutable;

/**
 * The measured length of each Case Order call recording, kept so nothing has to measure it twice.
 *
 * Written only with a length this server measured itself (`RecordingStreamer::measure()`, from the
 * provider's own response) — never with a value a browser reports.
 */
interface RecordingDurationRepositoryInterface
{
    /**
     * Current, unmeasured recordings whose next try has come, oldest first.
     *
     * @return list<RecordingToMeasure>
     */
    public function due(int $limit, int $maxAttempts, DateTimeImmutable $now): array;

    /**
     * Takes one recording for measuring until `$leaseUntil`.
     *
     * False when it is already measured or another run holds it — so two runs never measure the same
     * recording at the same time.
     */
    public function claim(int $recordingId, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool;

    /** Stores a measured length, once: true when this call stored it, false when one was already there. */
    public function record(int $recordingId, float $seconds, DateTimeImmutable $now): bool;

    /**
     * A measurement that did not produce a length; it may be tried again at `$retryAt`.
     *
     * `$countsAsAttempt` is false for "the provider is busy serving listeners", which is not the
     * recording's fault and must not use up its retries.
     */
    public function failed(int $recordingId, string $failure, DateTimeImmutable $retryAt, bool $countsAsAttempt): void;

    /** A measurement that cannot succeed by retrying: every retry is marked used, so none is made. */
    public function abandon(int $recordingId, string $failure, int $maxAttempts): void;

    /** How many current recordings have no length yet, and how many of those have used every retry. */
    public function unmeasured(int $maxAttempts): RecordingDurationBacklog;

    /** Gives recordings that used every retry a fresh start. Returns how many. */
    public function resetExhausted(int $maxAttempts): int;
}
