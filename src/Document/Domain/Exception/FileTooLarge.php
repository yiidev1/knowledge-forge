<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use function sprintf;

final class FileTooLarge extends UploadException
{
    public function errorCode(): string
    {
        return 'file_too_large';
    }

    public static function limit(int $actualBytes, int $limitBytes): self
    {
        return new self(
            sprintf(
                'This file is %s, which exceeds the %s limit.',
                self::human($actualBytes),
                self::human($limitBytes),
            ),
        );
    }

    private static function human(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);

        return $mb >= 1 ? sprintf('%.1f MB', $mb) : sprintf('%d KB', (int) ceil($bytes / 1024));
    }
}
