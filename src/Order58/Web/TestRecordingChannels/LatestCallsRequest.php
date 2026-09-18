<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function preg_match;
use function sprintf;

/**
 * The two parameters a Latest Calls request needs, and what makes them valid.
 *
 * The rules are the existing tool's, reproduced rather than imported — that directory is frozen and its
 * own isolation test forbids reaching into it. Both bounds come from there:
 *
 *  - an account id is `\d{1,9}` and positive, which bounds a typo rather than the provider's id space;
 *  - a limit is capped at 500, so a typo'd `?limit=` cannot ask the provider for an unreasonable page.
 *
 * The default limit is 10 rather than the other tool's 100: this page lists calls to pick one from, and
 * a hundred rows is a scroll rather than a choice.
 */
final readonly class LatestCallsRequest
{
    /** The provider's own ceiling, as the existing tool enforces it. */
    public const MAX_LIMIT = 500;

    /** Enough recent calls to find the one you want, few enough to read. */
    public const DEFAULT_LIMIT = 10;

    public function __construct(
        public int $accountId,
        public int $limit,
    ) {}

    /**
     * @return string|null the first problem found, or null when both values are safe to send
     */
    public static function validate(string $accountId, string $limit): ?string
    {
        return match (true) {
            self::positiveInt($accountId) === null
                => 'Account ID is required and must be a positive whole number.',
            self::positiveInt($limit) === null
                => 'Limit is required and must be a positive whole number.',
            (int) $limit > self::MAX_LIMIT
                => sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT),
            default => null,
        };
    }

    /**
     * Build after {@see validate()} has returned null.
     *
     * Returns null rather than throwing on bad input, so a caller that forgets to validate gets nothing
     * instead of a request built from a value nobody checked.
     */
    public static function fromStrings(string $accountId, string $limit): ?self
    {
        if (self::validate($accountId, $limit) !== null) {
            return null;
        }

        $account = self::positiveInt($accountId);
        $count = self::positiveInt($limit);

        return $account === null || $count === null ? null : new self($account, $count);
    }

    /**
     * Digits only, positive, and bounded at nine digits.
     *
     * Anchored with `\A`/`\z` rather than `^`/`$`: `$` also matches before a trailing newline, so
     * `871\n../../etc/passwd` would pass a `$`-anchored check on its first line alone. The value becomes
     * a URL path segment, so that distinction is not academic.
     */
    private static function positiveInt(string $raw): ?int
    {
        if (preg_match('/\A\d{1,9}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
