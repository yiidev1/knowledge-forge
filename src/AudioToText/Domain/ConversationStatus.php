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

        return $processing > 0 || $terminal > 0 ? self::PROCESSING : self::QUEUED;
    }

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::PROCESSING => 'Processing',
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
