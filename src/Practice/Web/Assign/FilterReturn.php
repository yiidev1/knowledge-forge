<?php

declare(strict_types=1);

namespace App\Practice\Web\Assign;

use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;

/**
 * The list filter an assignment came from, on its way back into the redirect.
 *
 * ## Why the rule is restated here rather than imported
 *
 * What a `?topics[]` value *means* is decided by the listing surface, which lives in a module this one is
 * not allowed to name at all — an isolation test enforces it, comments included. So this does not
 * interpret the value. It only decides whether a string is safe to put back into a URL, and the two
 * accepted shapes are the only two that surface emits.
 *
 * Anything else — a word, a negative number, an empty field, a crafted body — is dropped, and a body
 * made entirely of such values lands the reader on the unfiltered list: the page they would have got
 * had they never filtered.
 */
final class FilterReturn
{
    /** As many as the listing itself will read back. */
    public const MAX = 50;

    /**
     * The extra redirect arguments, empty when nothing recognisable was posted.
     *
     * Several values now, because the listing filters by several topics at once. They are returned as
     * `topics[0]`, `topics[1]` … — the shape the router appends to the query string and the shape the
     * listing reads back — rather than as one joined string, which would come back as a single
     * unrecognised value and silently drop the filter.
     *
     * @param mixed $posted the raw `filter_topics` field: a list, a single value, or nothing
     *
     * @return array<string, string>
     */
    public static function arguments(mixed $posted): array
    {
        $values = is_array($posted) ? array_values($posted) : [$posted];
        $arguments = [];
        $index = 0;

        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/\A(none|[1-9][0-9]{0,18})\z/', $value) !== 1) {
                continue;
            }

            if (in_array($value, $arguments, true)) {
                // The same choice twice is one choice. A crafted body repeating it a thousand times
                // must not produce a thousand-parameter URL.
                continue;
            }

            $arguments['topics[' . $index . ']'] = $value;
            ++$index;

            if ($index >= self::MAX) {
                break;
            }
        }

        return $arguments;
    }
}
