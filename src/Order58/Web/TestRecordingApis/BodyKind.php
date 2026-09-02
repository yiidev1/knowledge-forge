<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Whether a response body is something to read or something to count.
 *
 * The diagnostic page uses this to decide what it may print; the download endpoint uses it to decide
 * what it may hand the browser as a recording. Sharing the judgement means the page cannot claim a body
 * is a recording that the download route would refuse, or the reverse.
 */
final readonly class BodyKind
{
    /**
     * Definitely text — an error page, a JSON payload, a plain-text refusal.
     *
     * This is the half that matters for the download route: a textual body must never be delivered as
     * audio, whatever status accompanied it.
     */
    public static function isTextual(string $contentType): bool
    {
        $type = strtolower($contentType);

        if ($type === '') {
            return false;
        }

        return str_starts_with($type, 'text/')
            || str_contains($type, 'json')
            || str_contains($type, 'xml')
            || str_contains($type, 'html');
    }

    /**
     * Definitely not text. The Content-Type decides when it says something definite; otherwise a NUL
     * byte in what was read settles it.
     *
     * Deliberately not assuming `audio/wav`: the live endpoint answers `application/octet-stream`, and
     * a provider that switches to `audio/wav` or `binary/octet-stream` must keep working.
     */
    public static function isBinary(string $contentType, string $sample): bool
    {
        if (self::isTextual($contentType)) {
            return false;
        }

        $type = strtolower($contentType);

        if (
            str_starts_with($type, 'audio/')
            || str_starts_with($type, 'video/')
            || str_starts_with($type, 'image/')
            || str_contains($type, 'octet-stream')
        ) {
            return true;
        }

        return $sample !== '' && str_contains($sample, "\0");
    }
}
