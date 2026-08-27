<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function rtrim;
use function trim;

/**
 * Text hygiene for transcripts: UTF-8 validity, and safe shortening for table cells.
 *
 * The encoding guard matters at both ends of the pipeline. whisper.cpp writes whatever the model
 * produced, and a download that promises `charset=UTF-8` in its header must be telling the truth about
 * the bytes as well, or the browser renders mojibake and a downstream parser trips over a sequence that
 * cannot exist.
 */
final readonly class TranscriptText
{
    /**
     * Substitutes invalid sequences rather than rejecting the text.
     *
     * A transcript with one bad byte is still a useful transcript; refusing it would throw away a
     * ninety-second job over a character. The `mb_convert_encoding($s, 'UTF-8', 'UTF-8')` idiom is the
     * documented way to ask mbstring to replace anything invalid with a substitution character.
     */
    public static function toValidUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // The string overload cannot actually return false, but the signature allows it. Falling back
        // to the original is the wrong answer here: serving bytes that lie about their charset is
        // exactly what this method exists to prevent, so an unconvertible string yields nothing.
        return $converted === false ? '' : $converted;
    }

    /**
     * Normalises whitespace only.
     *
     * Deliberately conservative: this must not rewrite, summarise or "clean up" what was said. Names,
     * addresses, quantities and prices have to survive verbatim, so the only thing collapsed is runs of
     * horizontal whitespace, and line structure is left alone.
     */
    public static function normalizeWhitespace(string $text): string
    {
        $normalized = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $normalized = (string) preg_replace('/[ \t]*\R[ \t]*/u', "\n", $normalized);
        $normalized = (string) preg_replace('/\n{3,}/u', "\n\n", $normalized);

        return trim($normalized);
    }

    /**
     * A single-line preview for the conversions table.
     *
     * Truncation happens on the server, and the list query never selects a full transcript in the first
     * place — a table showing eighty characters per cell has no business pulling megabytes of
     * text across the wire to throw almost all of it away.
     */
    public static function preview(?string $text, int $length): ?string
    {
        if ($text === null) {
            return null;
        }

        $flattened = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($flattened === '') {
            return null;
        }

        if (mb_strlen($flattened) <= $length) {
            return $flattened;
        }

        $cut = mb_substr($flattened, 0, $length);

        // Prefer a word boundary, but only when one is reasonably close — otherwise a long unbroken
        // token (a URL, a run-together address) would collapse the preview to almost nothing.
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($length * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut) . '…';
    }
}
