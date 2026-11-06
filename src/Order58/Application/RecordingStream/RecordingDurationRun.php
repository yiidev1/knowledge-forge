<?php

declare(strict_types=1);

namespace App\Order58\Application\RecordingStream;

/** What one background measuring run did. */
final readonly class RecordingDurationRun
{
    private function __construct(
        public int $measured,
        /** Will be retried later, with backoff. */
        public int $failed,
        /** Gave up on: every retry used, or a failure retrying cannot fix. */
        public int $abandoned,
        /** Already measured, or held by another run, by the time this run reached it. */
        public int $skipped,
        /** Why the run ended before its batch did, or null when it finished the batch. */
        public ?string $stoppedBecause,
    ) {}

    public static function start(): self
    {
        return new self(0, 0, 0, 0, null);
    }

    public function measured(): self
    {
        return new self($this->measured + 1, $this->failed, $this->abandoned, $this->skipped, null);
    }

    public function failed(): self
    {
        return new self($this->measured, $this->failed + 1, $this->abandoned, $this->skipped, null);
    }

    public function abandoned(): self
    {
        return new self($this->measured, $this->failed, $this->abandoned + 1, $this->skipped, null);
    }

    public function skipped(): self
    {
        return new self($this->measured, $this->failed, $this->abandoned, $this->skipped + 1, null);
    }

    public function stopped(string $because): self
    {
        return new self($this->measured, $this->failed, $this->abandoned, $this->skipped, $because);
    }
}
