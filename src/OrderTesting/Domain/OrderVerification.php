<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use DateTimeImmutable;

/**
 * Whether one source order has been checked over, and by whom.
 *
 * ## An order with no row is unverified, not unknown
 *
 * Nothing creates a row until somebody acts, so the overwhelming majority of orders have none. That is
 * an answer, not missing data, and {@see notYetRecorded()} is how it is said — callers never branch on
 * null, and a page rendering a thousand orders needs a thousand of these without a thousand rows.
 *
 * ## `version` is not decoration
 *
 * Two people can have the same order open on two screens. Every write carries the version its author
 * was looking at, and the repository refuses one that has moved on, so a decision somebody has already
 * acted on cannot be silently reversed by a tab that was opened before it.
 */
final readonly class OrderVerification
{
    public function __construct(
        public int $storeSourceId,
        public int $sourceOrderId,
        public bool $isVerified,
        /** Null whenever this order is not currently verified — see the migration's CHECK. */
        public ?DateTimeImmutable $verifiedAt,
        public ?InitiatorType $verifiedByType,
        public ?int $verifiedById,
        /** Null only for an order nobody has ever acted on. */
        public ?DateTimeImmutable $changedAt,
        public ?InitiatorType $changedByType,
        public ?int $changedById,
        public int $version,
    ) {}

    /**
     * The state of an order nobody has touched: unverified, nobody named, version zero.
     *
     * Version zero is also what a writer sends to say "I believe there is no row here", which is how an
     * insert takes part in the same optimistic lock an update does.
     */
    public static function notYetRecorded(int $storeSourceId, int $sourceOrderId): self
    {
        return new self(
            storeSourceId: $storeSourceId,
            sourceOrderId: $sourceOrderId,
            isVerified: false,
            verifiedAt: null,
            verifiedByType: null,
            verifiedById: null,
            changedAt: null,
            changedByType: null,
            changedById: null,
            version: 0,
        );
    }
}
