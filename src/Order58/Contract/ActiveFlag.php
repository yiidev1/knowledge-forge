<?php

declare(strict_types=1);

namespace App\Order58\Contract;

use function is_bool;
use function is_string;
use function trim;

/**
 * Explicit, total normalization of the Order58 `account.active` source flag.
 *
 * The API has been observed to send this as a JSON boolean (`true`/`false`), an integer (`1`/`0`), or a
 * numeric string (`"1"`/`"0"`). All three are accepted. Anything else — a missing key, null, an empty
 * string, `2`, `"yes"` — is {@see normalize}d to `null`, meaning "unknown": the caller must NOT treat that
 * as inactive, because silently defaulting a missing flag to `false` would wrongly deactivate a store and
 * hide it from agents. `active` is the only source-active signal; it is never derived from any other field.
 */
final class ActiveFlag
{
    /**
     * @return bool|null true/false for a recognised value, or null when the value is missing or invalid.
     */
    public static function normalize(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1) {
            return true;
        }
        if ($value === 0) {
            return false;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '1') {
                return true;
            }
            if ($trimmed === '0') {
                return false;
            }
        }

        return null;
    }
}
