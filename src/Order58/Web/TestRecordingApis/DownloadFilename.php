<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use function ltrim;
use function preg_match;
use function preg_replace;
use function rawurldecode;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Turns the upstream `Content-Disposition` into a filename that is safe to hand a browser.
 *
 * The upstream name is a hint from an external server, never an instruction. It is not used as a path —
 * nothing here touches the filesystem — but it *is* echoed into a response header the browser will use
 * to name a file on disk, so it is reduced to `[A-Za-z0-9._-]` starting with an alphanumeric. Anything
 * that does not survive that becomes {@see FALLBACK_PATTERN}, built from the already-digits-validated
 * call session id.
 *
 * That rules out, in one pass: directory traversal (`../`), absolute paths, NUL and control bytes,
 * quotes or newlines that would break out of the header, and leading-dot hidden names.
 */
final readonly class DownloadFilename
{
    private const FALLBACK_PATTERN = 'recording-%s.wav';

    private const MAX_LENGTH = 120;

    /**
     * @param string $contentDisposition the upstream header, which may be absent or malformed
     * @param string $callSessionId already validated as digits-only, so the fallback is safe by construction
     */
    public static function fromContentDisposition(string $contentDisposition, string $callSessionId): string
    {
        $candidate = self::extract($contentDisposition);

        if ($candidate !== null) {
            $safe = self::sanitize($candidate);
            if ($safe !== null) {
                return $safe;
            }
        }

        return sprintf(self::FALLBACK_PATTERN, $callSessionId);
    }

    /** RFC 5987's `filename*` wins when present, since it is the encoded one; otherwise plain `filename`. */
    private static function extract(string $header): ?string
    {
        if (preg_match("/filename\*\s*=\s*UTF-8''([^;]+)/i", $header, $matches) === 1) {
            return rawurldecode(trim($matches[1]));
        }

        if (preg_match('/filename\s*=\s*"([^"]*)"/i', $header, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/filename\s*=\s*([^;]+)/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /** @return string|null the safe name, or null when nothing usable survived */
    private static function sanitize(string $candidate): ?string
    {
        // Defensive: drop anything before a separator, so `../../etc/passwd` reduces to `passwd` before
        // the character filter rather than relying on the filter alone.
        foreach (['/', '\\'] as $separator) {
            $at = strrpos($candidate, $separator);
            if ($at !== false) {
                $candidate = substr($candidate, $at + 1);
            }
        }

        $safe = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $candidate);
        $safe = ltrim($safe, '.-');

        if ($safe === '' || strlen($safe) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $safe) === 1 ? $safe : null;
    }
}
