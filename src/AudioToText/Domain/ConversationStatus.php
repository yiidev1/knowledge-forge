<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use function array_filter;
use function count;

/**
 * One state for a whole conversation, derived from its children.
 *
 * A separate upload is two independent jobs that the queue may run minutes apart, with unrelated work
 * in between. The store history still has to show the administrator *one* answer about it, so the
 * rule for turning several child states into one lives here — a pure function, unit tested — rather
 * than as conditionals spread across a template.
 *
 * Nothing about ordering is assumed. The children are ordinary FIFO rows and the worker may process
 * something else between them; {@see fromChildren()} reads whatever states exist at the moment it is
 * asked, so an interleaved third job changes nothing.
 */
enum ConversationStatus: string
{
    /**
     * Every recording in this upload is stored and none has been asked to transcribe.
     *
     * Not a stage on the way to anything: it is where a download-only import stops and waits for a
     * person. Mixed with any other state it loses — an upload where one channel is being transcribed is
     * doing something, and saying otherwise would hide it.
     */
    case NOT_REQUESTED = 'NOT_REQUESTED';

    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';

    /**
     * Some children finished and at least one failed.
     *
     * A distinct state rather than a blanket FAILED, because a failed Agent recording must not make a
     * perfectly good Customer transcript look lost. There is no automatic retry: the successful child
     * keeps its result and the failed one keeps its error.
     */
    case PARTIALLY_COMPLETED = 'PARTIALLY_COMPLETED';

    case FAILED = 'FAILED';

    /**
     * @param list<JobStatus> $children
     */
    public static function fromChildren(array $children): self
    {
        if ($children === []) {
            // No children is not a state a conversation can legitimately reach — the enqueue writes
            // the parent and its children in one transaction — but reporting QUEUED is the harmless
            // reading if a purge ever races the page.
            return self::QUEUED;
        }

        // Asked first, and only when it is true of every child. A recording nobody has asked about is
        // not waiting for a worker, so reporting it alongside work that is would be two different facts
        // under one word.
        if (self::countOf($children, JobStatus::NOT_REQUESTED) === count($children)) {
            return self::NOT_REQUESTED;
        }

        $completed = self::countOf($children, JobStatus::COMPLETED);
        $failed = self::countOf($children, JobStatus::FAILED);
        $terminal = $completed + $failed;

        if ($terminal === count($children)) {
            if ($failed === 0) {
                return self::COMPLETED;
            }

            return $completed === 0 ? self::FAILED : self::PARTIALLY_COMPLETED;
        }

        // Something is still outstanding. Work has started if any child is running or already
        // finished, which is what an administrator means by "processing" for the upload as a whole.
        $processing = self::countOf($children, JobStatus::PROCESSING);

        if ($processing > 0 || $terminal > 0) {
            return self::PROCESSING;
        }

        // Nothing running, nothing finished, and not every child is un-asked — so at least one has been
        // requested and is waiting. That is what the upload as a whole is doing.
        return self::QUEUED;
    }

    public function label(): string
    {
        return match ($this) {
            self::NOT_REQUESTED => 'Ready for transcription',
            self::QUEUED => 'Transcription requested',
            self::PROCESSING => 'Transcribing',
            self::COMPLETED => 'Completed',
            self::PARTIALLY_COMPLETED => 'Partially completed',
            self::FAILED => 'Failed',
        };
    }

    /**
     * The `a2t-badge--*` modifier for this state.
     *
     * Derived here rather than as `strtolower($status->value)` in a template, because
     * PARTIALLY_COMPLETED would produce an underscore and silently match no rule at all — an unstyled
     * badge is exactly the kind of miss nobody notices until the one state that hits it appears.
     */
    public function badgeModifier(): string
    {
        return match ($this) {
            // The same modifier the queued state uses: both are "nothing has happened yet" to look at,
            // and inventing a sixth badge colour for a state that is waiting on a person adds nothing.
            self::NOT_REQUESTED => 'queued',
            self::QUEUED => 'queued',
            self::PROCESSING => 'processing',
            self::COMPLETED => 'completed',
            self::PARTIALLY_COMPLETED => 'partially-completed',
            self::FAILED => 'failed',
        };
    }

    /** Whether nothing further will happen without a new upload. */
    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::PARTIALLY_COMPLETED || $this === self::FAILED;
    }

    /**
     * @param list<JobStatus> $children
     */
    private static function countOf(array $children, JobStatus $status): int
    {
        return count(array_filter($children, static fn(JobStatus $s): bool => $s === $status));
    }
}
