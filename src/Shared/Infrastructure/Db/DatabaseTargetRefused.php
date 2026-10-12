<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use RuntimeException;

/**
 * A schema change was refused because the target could not be proved safe.
 *
 * Always fatal, never a warning. The whole point of the guard is that an unproven target stops the
 * operation rather than producing a message somebody reads afterwards.
 */
final class DatabaseTargetRefused extends RuntimeException
{
    public function __construct(string $why)
    {
        parent::__construct('Refusing to change the schema — ' . $why);
    }
}
