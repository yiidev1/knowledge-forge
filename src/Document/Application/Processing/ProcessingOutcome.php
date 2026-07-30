<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

/**
 * The result of one processing run for a document.
 *
 * `Ready` and `Failed` are terminal; `Indexing` means the content was uploaded and is still being
 * indexed by the provider (a later run polls it); `Requeued` means a transient failure will be retried
 * after backoff.
 */
enum ProcessingOutcome
{
    case Ready;
    case Indexing;
    case Requeued;
    case Failed;

    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
