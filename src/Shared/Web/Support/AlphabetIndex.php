<?php

declare(strict_types=1);

namespace App\Shared\Web\Support;

use function ltrim;
use function mb_strtoupper;
use function mb_substr;
use function preg_match;
use function range;

/**
 * Alphabetical bucketing shared by the admin and agent store directories.
 *
 * A name buckets under the uppercase of its first non-whitespace character; anything that is not A–Z
 * (digits, symbols, other scripts) buckets under "#". Comparison is case-insensitive and leading whitespace
 * is ignored, so "  aardvark" and "Aardvark" share a bucket. The rendered nav is always "All", A–Z, then "#".
 */
final class AlphabetIndex
{
    public const ALL = 'all';
    public const OTHER = '#';

    /**
     * The bucket letter for a store name: "A".."Z" or "#".
     */
    public static function letterFor(string $name): string
    {
        $trimmed = ltrim($name);
        if ($trimmed === '') {
            return self::OTHER;
        }

        $first = mb_strtoupper(mb_substr($trimmed, 0, 1));

        return preg_match('/^[A-Z]$/', $first) === 1 ? $first : self::OTHER;
    }

    /**
     * The ordered letter buckets after "All": A–Z then "#".
     *
     * @return list<string>
     */
    public static function letters(): array
    {
        $letters = range('A', 'Z');
        $letters[] = self::OTHER;

        return $letters;
    }

    /**
     * Normalizes a requested letter (from the query string) to a canonical bucket, defaulting to "All".
     */
    public static function normalize(?string $requested): string
    {
        if ($requested === null || $requested === '' || $requested === self::ALL) {
            return self::ALL;
        }
        if ($requested === self::OTHER) {
            return self::OTHER;
        }

        $upper = mb_strtoupper($requested);

        return preg_match('/^[A-Z]$/', $upper) === 1 ? $upper : self::ALL;
    }
}
