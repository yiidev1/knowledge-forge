<?php

declare(strict_types=1);

namespace App\Document\Application;

use function preg_replace;
use function rawurlencode;
use function str_replace;
use function trim;

/**
 * Builds a safe Content-Disposition filename parameter from a display name.
 */
final class ContentDisposition
{
    public static function header(string $disposition, string $filename): string
    {
        $safe = self::sanitizeFilename($filename);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $safe) ?? 'download';
        $ascii = $ascii === '' ? 'download' : $ascii;

        return $disposition . '; filename="' . str_replace(['\\', '"'], ['_', '_'], $ascii)
            . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
    }

    public static function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(["\0", "\r", "\n"], '', $filename);
        $filename = str_replace(['/', '\\'], '_', $filename);
        $filename = trim($filename);

        return $filename === '' ? 'download' : $filename;
    }
}
