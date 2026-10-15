<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * What somebody did to an order's verification.
 *
 * Two cases, and the database CHECK on `order_testing_verification_events.action` admits nothing else.
 * Stored as the history rather than derived from the state it produced: "verified" and "verified again
 * after somebody removed it" are the same state and different events, and only the second is worth
 * reading an audit trail for.
 */
enum VerificationAction: string
{
    case Verified = 'VERIFIED';
    case Unverified = 'UNVERIFIED';

    /** The state this action leaves the order in. */
    public function verified(): bool
    {
        return $this === self::Verified;
    }

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::Unverified => 'Unverified',
        };
    }
}
