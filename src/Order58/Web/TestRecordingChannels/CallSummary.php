<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use DateTimeImmutable;

use function is_array;
use function is_scalar;
use function preg_match;
use function strlen;
use function substr;

/**
 * One row from the Latest Calls response.
 *
 * ## Only the fields the API actually returns
 *
 * Three, because three is what the existing working tool reads from this endpoint: `callTime`,
 * `callSessionId` and `orderId`. Nothing else is invented — there is no caller or callee number here,
 * because nothing in this application has ever seen the provider return one. If the API does carry more,
 * it will show up in the raw response the page prints, and can be added then with evidence.
 *
 * ## The call session id is used exactly as given
 *
 * It is never derived, generated, or substituted with a merchant id. It becomes the Recording ID, which
 * becomes the filename — so a value invented here would produce a request for a file that cannot exist.
 */
final readonly class CallSummary
{
    public function __construct(
        public string $callSessionId,
        /** Whatever the provider sent, verbatim. Its format is not documented anywhere in this project. */
        public string $callTime,
        public string $orderId,
    ) {}

    /**
     * @param array<string, mixed> $row one decoded object from the response array
     */
    public static function fromArray(array $row): self
    {
        return new self(
            self::scalar($row['callSessionId'] ?? null),
            self::scalar($row['callTime'] ?? null),
            self::scalar($row['orderId'] ?? null),
        );
    }

    /** A row with no session id is useless here — there is nothing to select. */
    public function isUsable(): bool
    {
        return $this->callSessionId !== '';
    }

    /**
     * Whether this id would be accepted as a Recording ID.
     *
     * Checked before the page offers a "Use this call" button, so selecting a row can never pre-fill a
     * value the channel form would immediately refuse.
     */
    public function hasValidRecordingId(): bool
    {
        return preg_match('/\A\d{1,20}\z/', $this->callSessionId) === 1;
    }

    /**
     * The `YYYY-MM-DD` the recording-fetch API wants, **only when it can be read with certainty**.
     *
     * ## Why this is deliberately conservative
     *
     * The existing working tool does **not** derive the fetch `time` from a call's `callTime` — its
     * "Use below" link carries the form's existing date through untouched. So there is no proven
     * conversion in this project to copy, and `callTime`'s format is documented nowhere.
     *
     * Rather than guess, this reads a date only when the value *begins* with something that is already
     * the exact format the fetch API takes, and which is a real calendar date — `2026-02-31` is rejected.
     * Anything else returns null and the Time field is left exactly as the operator set it.
     *
     * A returned date is still a derivation rather than a fact, so the page labels it as one and asks
     * for it to be checked.
     */
    public function derivedDate(): ?string
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})/', $this->callTime, $matches) !== 1) {
            return null;
        }

        $candidate = $matches[1];
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);

        return $parsed !== false && $parsed->format('Y-m-d') === $candidate ? $candidate : null;
    }

    /** Short enough for a table cell, without hiding that the full value was something else. */
    public function shortCallTime(int $maxLength = 40): string
    {
        return $this->callTime === ''
            ? '—'
            : (strlen($this->callTime) > $maxLength ? substr($this->callTime, 0, $maxLength) . '…' : $this->callTime);
    }

    /**
     * Decode the whole response body into rows.
     *
     * Best-effort by design, exactly as the existing tool's own decoder is: a body that is not a list of
     * call objects yields no rows, and the raw response printed above the table still tells the whole
     * story. Throwing here would replace a readable diagnostic with a stack trace.
     *
     * @return list<self>
     */
    public static function fromDecoded(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];

        /** @var mixed $item */
        foreach ($decoded as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $rows[] = self::fromArray($item);
            }
        }

        return $rows;
    }

    private static function scalar(mixed $raw): string
    {
        return is_scalar($raw) ? (string) $raw : '';
    }
}
