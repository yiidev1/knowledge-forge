<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * Where one recording channel's import has got to.
 *
 * Six states, and the three terminal-but-not-failed ones are the point. A caller/callee recording that
 * does not exist is not a failure — separated channels are generated only for the merchants on the
 * client's list, so a 404 there is a fact about the merchant, not about the request. A recording too big
 * for the transcription limits is not a failure either; it is a decision this application has not been
 * given the authority to make. Collapsing either into "Failed" would put a red badge next to a call that
 * imported perfectly well, and would invite a retry that can only fail again.
 *
 * `imported` is terminal **for the import**. What happens to the recording afterwards — queued,
 * transcribing, corrected — belongs to the Audio-to-Text job and is shown on the store page. This enum
 * deliberately does not mirror those states: two places reporting one truth is how they start disagreeing.
 */
enum Order58ImportStatus: string
{
    /** Queued for fetching. Nothing has been requested from the provider yet. */
    case Pending = 'PENDING';

    /** Claimed by the worker. The provider request is in flight, or its result is being written. */
    case Fetching = 'FETCHING';

    /** Handed to the transcription pipeline. The Audio-to-Text job owns it from here. */
    case Imported = 'IMPORTED';

    /** The provider has no such recording: this merchant does not produce this channel. */
    case NotAvailable = 'NOT_AVAILABLE';

    /** A real recording, past the configured size or duration ceiling. Not sent to transcription. */
    case TooLarge = 'TOO_LARGE';

    /** Attempts exhausted, or a refusal retrying cannot fix. */
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Fetching => 'Fetching',
            self::Imported => 'Imported',
            self::NotAvailable => 'Not available',
            self::TooLarge => 'Too large',
            self::Failed => 'Failed',
        };
    }

    /**
     * The admin badge modifier, from the palette `admin.css` actually defines.
     *
     * Only `success|error|warning|info|muted` exist as `.badge--*` rules. `SyncRunStatus` returns names
     * outside that set and its badges render unstyled on the Data Management page; this follows
     * {@see \App\Rules\Domain\RuleReadinessStatus} instead, which does not.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Imported => 'success',
            self::Pending, self::Fetching => 'info',
            self::TooLarge => 'warning',
            self::Failed => 'error',
            // Not a problem, and not progress either: the merchant simply has no such file.
            self::NotAvailable => 'muted',
        };
    }

    /** Whether the worker will do anything more with this row. */
    public function isSettled(): bool
    {
        return $this !== self::Pending && $this !== self::Fetching;
    }

    /**
     * Whether a person may ask for this to be tried again.
     *
     * Only an outright failure. A missing channel and an oversized recording are both settled facts
     * about the provider's file, and retrying either would spend a request to learn what is already
     * recorded.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
