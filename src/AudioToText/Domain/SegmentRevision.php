<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

/**
 * One recorded correction, holding the conversation as it stood *before* the change.
 *
 * Storing the prior state rather than the new one matches `message_revisions` and makes the trail walk
 * backwards: the current reviewed conversation is on the job, and each revision peels one edit off it
 * until the first, which carries a copy of the machine's own segments.
 *
 * That is also why a text edit needs no dedicated column. The snapshot holds the previous wording, so
 * comparing it with the state that replaced it shows exactly which turn was reworded and how — while
 * `transcript` keeps the original transcription regardless.
 */
final readonly class SegmentRevision
{
    public function __construct(
        public int $id,
        public int $jobId,
        public int $revisionNumber,
        /** The reviewed conversation *before* this operation, as stored JSON. */
        public string $segmentsJson,
        public ReviewOperation $operation,
        public string $editedByType,
        public int $editedById,
        /** Joined from `admin_users`; null when the account has since been removed. */
        public ?string $editedByUsername,
        public DateTimeImmutable $createdAt,
    ) {}
}
