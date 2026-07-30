<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use finfo;

use function is_string;

use const FILEINFO_MIME_TYPE;

/**
 * Detects a file's MIME type from its actual contents, using libmagic via ext-fileinfo.
 *
 * This is the crux of upload safety: the browser-supplied `Content-Type` and the filename extension are
 * attacker-controlled and are never trusted. Only the sniffed type decides whether a file is accepted
 * and what extension it is stored with, so a PHP script renamed to `.pdf` is caught here.
 */
final class MimeTypeDetector
{
    /**
     * @return string The detected MIME type, or '' when it cannot be determined.
     */
    public function detect(string $absolutePath): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        return is_string($mime) ? $mime : '';
    }
}
