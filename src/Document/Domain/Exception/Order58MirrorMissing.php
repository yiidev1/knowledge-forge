<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

final class Order58MirrorMissing extends DomainException
{
    public function errorCode(): string
    {
        return 'order58_mirror_missing';
    }

    public static function forSource(): self
    {
        return new self(
            'The Order58 source for this document is not available in the local mirror. Sync Order58 first.',
        );
    }
}
