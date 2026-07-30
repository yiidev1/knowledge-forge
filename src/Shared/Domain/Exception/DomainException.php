<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use RuntimeException;

/**
 * Base for every expected application failure.
 *
 * These carry a stable `errorCode` and a message that is already safe to show a user. Unexpected
 * failures stay as ordinary exceptions and are rendered as a generic error, so an internal path or an
 * SQL fragment never reaches the browser.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Stable, machine-readable identifier, e.g. `knowledge_base_not_found`. Persisted in `error_code`
     * columns and used for translation lookups, so it must not change once released.
     */
    abstract public function errorCode(): string;
}
