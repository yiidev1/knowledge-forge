<?php

declare(strict_types=1);

namespace App\Ai\Domain;

/**
 * State of a non-idempotent OpenAI operation in the ledger.
 *
 * pending → in_flight → succeeded is the happy path. A failure with no possible side effect returns to
 * pending (safe to retry fresh); an ambiguous failure becomes needs_reconcile (must query the provider
 * before retrying); exhausting attempts, or an unrecoverable error, becomes failed.
 */
enum AiOperationStatus: string
{
    case Pending = 'pending';
    case InFlight = 'in_flight';
    case Succeeded = 'succeeded';
    case NeedsReconcile = 'needs_reconcile';
    case Failed = 'failed';
}
