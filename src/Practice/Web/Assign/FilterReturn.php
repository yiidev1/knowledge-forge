<?php

declare(strict_types=1);

namespace App\Practice\Web\Assign;

use function preg_match;

/**
 * The list filter an assignment came from, on its way back into the redirect.
 *
 * ## Why the rule is restated here rather than imported
 *
 * What a `?topic=` value *means* is decided by the listing surface, which lives in a module this one is
 * not allowed to name at all — an isolation test enforces it, comments included. So this does not
 * interpret the value. It only decides whether a string is safe to put back into a URL, and the two
 * accepted shapes are the only two that surface emits.
 *
 * Anything else — a word, a negative number, an empty field, a crafted body — returns null and the
 * reader lands on the unfiltered list, which is the page they would have got had they never filtered.
 */
final class FilterReturn
{
    /** @return array<string, string> the extra redirect arguments, empty when there is no filter */
    public static function arguments(string $posted): array
    {
        return preg_match('/\A(none|[1-9][0-9]{0,18})\z/', $posted) === 1
            ? ['topic' => $posted]
            : [];
    }
}
