<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * A document cannot be ingested automatically and needs a person to intervene (e.g. a scanned PDF that
 * exceeds the vision limits). A permanent failure: the worker marks the document failed with this
 * message and does not retry, rather than looping or silently indexing nothing.
 */
final class ManualReviewRequired extends DomainException
{
    public function errorCode(): string
    {
        return 'requires_manual_review';
    }
}
