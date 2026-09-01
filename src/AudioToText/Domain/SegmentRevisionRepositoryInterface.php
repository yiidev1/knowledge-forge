<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The audit trail for manual conversation corrections.
 *
 * Append-only by design: there is no update and no delete. A correction history that can be edited is
 * not an audit trail, and the one case that looks like deletion — an administrator reverting to the
 * machine's result — is itself recorded as a {@see ReviewOperation::Revert} entry.
 */
interface SegmentRevisionRepositoryInterface
{
    /**
     * Record the state that existed before an operation.
     *
     * @param string $priorSegmentsJson the reviewed conversation before this change; for a job's first
     *                                  correction this is a copy of the machine's own segments, so every
     *                                  revision is self-contained
     *
     * @return int the revision number assigned
     */
    public function add(
        int $jobId,
        string $priorSegmentsJson,
        ReviewOperation $operation,
        int $editedByAdminId,
    ): int;

    /**
     * A job's corrections, oldest first.
     *
     * @return list<SegmentRevision>
     */
    public function forJob(int $jobId): array;

    public function countForJob(int $jobId): int;
}
