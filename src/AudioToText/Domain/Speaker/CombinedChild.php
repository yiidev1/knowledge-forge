<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SpeakerRole;

/**
 * One side of a deterministic call, and the row that owns its words.
 *
 * Every field here exists so that a correction made from the combined view lands on **this** job: the
 * public id addresses the existing review routes, and `reviewCount` is the optimistic lock those routes
 * check. The two sides of a call are two independent rows with two independent counters, which is the
 * single most important fact about editing a combined conversation — a form carrying the mixed
 * recording's version, or the other side's, would be refused at best and would overwrite a concurrent
 * correction at worst.
 *
 * `turns` is the child's own {@see ReviewedConversationTurns}, carried rather than re-read, because
 * three different questions are asked of it downstream — what the text is, whether a merge is allowed,
 * and which revision belongs to which turn — and asking them of three separately-loaded copies is how
 * the answers start disagreeing.
 */
final readonly class CombinedChild
{
    public function __construct(
        /** The row id, for the audit-trail lookup. Never rendered. */
        public int $jobId,
        /** What every review URL built for this side's turns is keyed on. */
        public string $jobPublicId,
        /** CUSTOMER or AGENT — established at upload, never inferred from the audio. */
        public SpeakerRole $role,
        /** The optimistic lock every correction to this side must carry. */
        public int $reviewCount,
        public JobStatus $status,
        /** Whether anybody has corrected this side yet. */
        public bool $isReviewed,
        /** This side's own turns, in its own order, with its own indices. */
        public ReviewedConversationTurns $turns,
    ) {}

    public function turnCount(): int
    {
        return $this->turns->count();
    }

    public function hasTurns(): bool
    {
        return !$this->turns->isEmpty();
    }
}
