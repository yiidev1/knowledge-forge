<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Conversion\AiAudio;

use App\AudioToText\Domain\TranscriptionJob;
use DateTimeImmutable;

use function substr;

/**
 * Which transcript the audio is — or would be — a reading of.
 *
 * Shown because "generate audio from the transcript" is only trustworthy if an administrator can see
 * *which* transcript, and confirm it is the corrected one rather than the raw machine output. It is the
 * difference between believing the audio says "wonton" and knowing it does.
 */
final readonly class TranscriptSource
{
    public function __construct(
        /** True once a human correction exists — the reviewed layer is then what gets spoken. */
        public bool $isCorrected,
        /**
         * `review_count`, shown as a revision number.
         *
         * Displayed but never used to decide anything: it counts *operations*, so a revert increments it
         * while restoring identical words. Staleness is decided by the digest below, which cannot be
         * wrong in either direction.
         */
        public int $revision,
        public ?DateTimeImmutable $lastEditedAt,
        public ?string $lastEditedBy,
        /** The digest of the current text, shown short so it can be eyeballed against a file's name. */
        public string $hash,
    ) {}

    public static function for(TranscriptionJob $job, string $hash): self
    {
        return new self(
            $job->isReviewed(),
            $job->reviewCount,
            $job->reviewedAt,
            $job->reviewedByUsername,
            $hash,
        );
    }

    public function label(): string
    {
        return $this->isCorrected ? 'Corrected transcript' : 'Original machine transcript';
    }

    public function shortHash(): string
    {
        return $this->hash === '' ? '—' : substr($this->hash, 0, 8);
    }
}
