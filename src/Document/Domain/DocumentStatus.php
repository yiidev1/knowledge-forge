<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The processing lifecycle of an uploaded document.
 *
 * A web upload only ever reaches {@see Queued}. The background worker (Phase 6) moves it through
 * {@see Processing}/{@see Indexing} to {@see Ready} or {@see Failed}. {@see Deleted} is a soft state:
 * the row is kept for audit and to free the dedupe slot, while the stored file is removed.
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Processing = 'processing';
    case Indexing = 'indexing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Indexing => 'Indexing',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Badge style token used by the admin CSS ({@see badge--*}).
     */
    public function badge(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Failed => 'error',
            self::Processing, self::Indexing, self::Queued => 'info',
            self::Uploaded => 'muted',
            self::Deleted => 'muted',
        };
    }

    /**
     * Whether the document is mid-flight, so the UI can show a spinner/"in progress" affordance.
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Processing, self::Indexing => true,
            default => false,
        };
    }

    /**
     * Whether a failed document may be retried by the operator.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
