<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A record does not exist, or exists but is not reachable through the requested parent.
 *
 * Both cases produce the same exception on purpose: a document that belongs to another knowledge base
 * must be indistinguishable from one that was never created, otherwise the 404/403 difference leaks
 * which ids exist.
 */
final class NotFoundException extends DomainException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
