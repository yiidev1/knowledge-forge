<?php

declare(strict_types=1);

namespace App\Document\Application\Text;

use function array_map;
use function explode;
use function implode;
use function mb_check_encoding;
use function preg_replace;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Deterministic normalization for text knowledge — uploaded `.txt`/`.md` files and manual entries alike.
 *
 * The same input always yields byte-identical output: a UTF-8 BOM is stripped, line endings are unified to
 * LF, trailing whitespace is removed, runs of blank lines are collapsed, and exactly one trailing newline
 * is added. This is what makes "unchanged content does not re-index" reliable and lets the per-knowledge-
 * base content dedupe compare like with like.
 */
final class PlainTextNormalizer
{
    public static function isValidUtf8(string $content): bool
    {
        return mb_check_encoding($content, 'UTF-8');
    }

    public static function normalize(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = array_map(rtrim(...), explode("\n", $content));
        $joined = implode("\n", $lines);
        $content = trim(preg_replace("/\n{3,}/", "\n\n", $joined) ?? $joined);

        return $content === '' ? '' : $content . "\n";
    }
}
