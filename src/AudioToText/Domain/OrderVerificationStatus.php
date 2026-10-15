<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * What one order's row should say about verification, and what its form should send.
 *
 * A view of somebody else's table, read by name — this module renders the Order Testing page but does
 * not own verification, the same arrangement {@see AssignedTopic} has with topics.
 *
 * `version` is here because the control posts it back. It is the version the reader was looking at, and
 * the endpoint refuses a write whose version has moved on, so a tab left open across somebody else's
 * change cannot silently reverse it.
 */
final readonly class OrderVerificationStatus
{
    public function __construct(
        public bool $isVerified,
        public int $version,
        /** Formatted where the page's timezone is known, or null for an order nobody has verified. */
        public ?string $verifiedLine,
    ) {}

    /** The state of an order nobody has acted on — no row, and nothing to say about it. */
    public static function none(): self
    {
        return new self(isVerified: false, version: 0, verifiedLine: null);
    }
}
