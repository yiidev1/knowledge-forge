<?php

declare(strict_types=1);

namespace App\Order58\Application;

/**
 * The result of one daily-scheduler invocation for a sync type.
 */
enum DailySyncOutcome: string
{
    /** The daily wall-clock time (02:00/03:00 NY) has not passed yet today. */
    case NotDue = 'not_due';
    /** Today's run was already successfully scheduled for this type (idempotent no-op). */
    case AlreadyScheduled = 'already_scheduled';
    /** A run was enqueued for today. */
    case Enqueued = 'enqueued';
    /** An Order58 rules/knowledge run was already active, so today coalesced onto it (still satisfied). */
    case Coalesced = 'coalesced';
    /** The enqueue failed; the reservation is left retryable for the next catch-up pass. */
    case Failed = 'failed';
}
