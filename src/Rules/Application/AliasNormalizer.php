<?php

declare(strict_types=1);

namespace App\Rules\Application;

use function mb_strtolower;
use function preg_replace;
use function trim;

/**
 * Deterministic normalization of store names/aliases for word-boundary-safe matching.
 *
 * Lower-cases, replaces every run of non-alphanumeric characters with a single space, and trims — so
 * "Moon Temple", "moon  temple" and "Moon-Temple!" all normalize to "moon temple". Matching then compares
 * space-padded token strings, so an alias only matches a whole word, never an unsafe substring.
 */
final class AliasNormalizer
{
    public static function normalize(string $value): string
    {
        $lower = mb_strtolower($value);
        $spaced = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? $spaced);
    }
}
