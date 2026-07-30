<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use function bin2hex;
use function random_bytes;

/**
 * Produces the internal storage name for an uploaded file — never derived from the user's filename.
 *
 * The stored name is a random token plus the extension taken from the DETECTED MIME type. Because no
 * part of it comes from user input, path traversal (`../`), null bytes, double extensions and
 * executable names are structurally impossible in the stored name. The original filename is kept only
 * as a display string in the database.
 */
final class SafeFilenameGenerator
{
    /**
     * A 32-hex-character token (128 bits), also stored on the document row for later reconciliation of
     * the OpenAI upload.
     */
    public function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param string $token     A token from {@see token()}.
     * @param string $extension The canonical extension from the allowlist (no leading dot).
     */
    public function filename(string $token, string $extension): string
    {
        return $token . '.' . $extension;
    }
}
