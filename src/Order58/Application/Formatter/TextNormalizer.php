<?php

declare(strict_types=1);

namespace App\Order58\Application\Formatter;

use function array_map;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function str_replace;
use function trim;

/**
 * Deterministic UTF-8 text normalization shared by the generated-document formatters. The same input
 * always yields byte-identical output — line endings are unified to LF, trailing whitespace is stripped,
 * and runs of blank lines are collapsed — so an unchanged source record never produces a "changed"
 * checksum and never triggers a needless re-index.
 */
final class TextNormalizer
{
    /**
     * Collapses a value to a single trimmed line (for labelled fields).
     */
    public static function inline(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Normalizes a multi-line block: LF endings, no trailing spaces, at most one blank line between
     * paragraphs.
     */
    public static function content(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map(rtrim(...), explode("\n", $value));
        $value = implode("\n", $lines);
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;

        return trim($value);
    }

    /**
     * Finalizes a whole document: LF endings, trimmed, with exactly one trailing newline.
     */
    public static function block(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map(rtrim(...), explode("\n", $value));

        return rtrim(implode("\n", $lines)) . "\n";
    }
}
