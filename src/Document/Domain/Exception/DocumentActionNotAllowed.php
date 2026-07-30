<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use App\Document\Domain\DocumentStatus;
use App\Shared\Domain\Exception\DomainException;

use function sprintf;

/**
 * An operator action (retry, re-index, process-now) was requested against a document whose current
 * status does not permit it — e.g. re-indexing a document that never finished indexing. Guards the
 * enqueue-only web triggers server-side, independently of which buttons the UI happens to render.
 */
final class DocumentActionNotAllowed extends DomainException
{
    public function errorCode(): string
    {
        return 'document_action_not_allowed';
    }

    public static function forStatus(string $action, DocumentStatus $status): self
    {
        return new self(
            sprintf('This document cannot be %s while it is %s.', $action, $status->label()),
        );
    }
}
