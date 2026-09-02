<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use DateTimeImmutable;

use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * The four parameters a Fetch Recording call needs, and the one definition of what makes them valid.
 *
 * Both routes validate through here — the diagnostic page and the download endpoint — so the download
 * cannot be reached with input the test form would have rejected. A second copy of these rules would
 * drift, and the copy that drifted would be the one guarding the endpoint that streams bytes.
 */
final readonly class RecordingRequest
{
    /** A call session id is a numeric identifier; this bounds a typo, not the provider's id space. */
    public const MAX_ID_DIGITS = 20;

    /** Company and name are free text on the provider's side, so cap them at something sane. */
    public const MAX_TEXT_LENGTH = 100;

    public function __construct(
        public string $callSessionId,
        public string $time,
        public string $company,
        public string $name,
    ) {}

    /**
     * @return string|null the first problem found, or null when every value is safe to send
     */
    public static function validate(string $callSessionId, string $time, string $company, string $name): ?string
    {
        return match (true) {
            preg_match('/^\d{1,' . self::MAX_ID_DIGITS . '}$/', $callSessionId) !== 1
                => 'Call Session ID is required and must be digits only.',
            !self::isCalendarDate($time)
                => 'Time is required and must be a real date in YYYY-MM-DD format.',
            !self::isSafeText($company)
                => sprintf('Company is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            !self::isSafeText($name)
                => sprintf('Name is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            default => null,
        };
    }

    /** A real calendar date, not merely something shaped like one: `2026-02-31` is rejected. */
    private static function isCalendarDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
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
