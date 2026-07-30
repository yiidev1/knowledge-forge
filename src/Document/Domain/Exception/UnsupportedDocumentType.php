<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use function implode;
use function sprintf;

/**
 * The file's server-detected type is not on the allowlist.
 *
 * The message names the detected type and the accepted ones. It never echoes the browser-supplied MIME
 * or filename, since those are untrusted.
 */
final class UnsupportedDocumentType extends UploadException
{
    public function errorCode(): string
    {
        return 'unsupported_document_type';
    }

    /**
     * @param list<string> $accepted
     */
    public static function detected(string $detectedMime, array $accepted): self
    {
        return new self(
            sprintf(
                'This file type (%s) is not supported. Accepted types: %s.',
                $detectedMime === '' ? 'unknown' : $detectedMime,
                implode(', ', $accepted),
            ),
        );
    }
}
