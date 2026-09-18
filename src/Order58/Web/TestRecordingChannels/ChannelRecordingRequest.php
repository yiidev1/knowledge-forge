<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use DateTimeImmutable;

use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Everything a channel request needs, and the one definition of what makes it safe to send.
 *
 * The page and the download endpoint both validate through here, so the download cannot be reached with
 * input the form would have rejected. A second copy of these rules would drift, and the copy that
 * drifted would be the one guarding the endpoint that streams bytes.
 *
 * ## The recording id is digits only, and that is load-bearing
 *
 * It becomes part of a filename — `22342359-caller.wav` — and part of a URL path segment. Digits-only
 * makes traversal (`../`), absolute paths, NUL bytes, quotes, newlines and separators structurally
 * impossible rather than filtered out afterwards. {@see RecordingChannel::fileNameFor()} has no way to
 * refuse, so nothing may reach it that has not been through here first.
 *
 * ## Merchant id is collected but not sent
 *
 * Stated plainly because it would otherwise look like an oversight: the confirmed recording endpoint
 * takes no merchant parameter at all. It is validated and shown in the diagnostics for the operator's
 * record, and it is **not** put on the wire, because inventing a parameter the provider has not asked
 * for would be a guess dressed as a feature.
 */
final readonly class ChannelRecordingRequest
{
    /** A recording id is a numeric identifier; this bounds a typo, not the provider's id space. */
    public const MAX_ID_DIGITS = 20;

    /** Company and name are free text on the provider's side, so cap them at something sane. */
    public const MAX_TEXT_LENGTH = 100;

    public function __construct(
        public string $recordingId,
        public string $merchantId,
        public string $time,
        public string $company,
        public string $name,
    ) {}

    /**
     * @return string|null the first problem found, or null when every value is safe to send
     */
    public static function validate(
        string $recordingId,
        string $merchantId,
        string $time,
        string $company,
        string $name,
    ): ?string {
        return match (true) {
            !self::isNumericId($recordingId)
                => 'Recording ID is required and must be digits only.',
            !self::isNumericId($merchantId)
                => 'Merchant ID is required and must be digits only.',
            !self::isCalendarDate($time)
                => 'Time is required and must be a real date in YYYY-MM-DD format.',
            !self::isSafeText($company)
                => sprintf('Company is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            !self::isSafeText($name)
                => sprintf('Name is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            default => null,
        };
    }

    /**
     * Digits only — no sign, no separator, no whitespace, no traversal sequence.
     *
     * Anchored with `\A`/`\z` rather than `^`/`$`, because `$` also matches before a trailing newline:
     * `22342359\n../../etc/passwd` would pass a `$`-anchored check on the first line alone.
     */
    private static function isNumericId(string $value): bool
    {
        return preg_match('/\A\d{1,' . self::MAX_ID_DIGITS . '}\z/', $value) === 1;
    }

    /** A real calendar date, not merely something shaped like one: `2026-02-31` is rejected. */
    private static function isCalendarDate(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** Non-empty, bounded, and free of control characters that have no business in a query string. */
    private static function isSafeText(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= self::MAX_TEXT_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
