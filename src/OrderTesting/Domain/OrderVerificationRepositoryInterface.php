<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use DateTimeImmutable;

/**
 * Where one source order's verification state is kept, and how it changes.
 *
 * Both methods key on `(storeSourceId, sourceOrderId)` because that pair is what identifies an order
 * in this application — `source_order_id` alone is not unique across stores, and the table's UNIQUE
 * key says so in the only place that can enforce it.
 */
interface OrderVerificationRepositoryInterface
{
    /** Never null: an order with no row is {@see OrderVerification::notYetRecorded()}. */
    public function find(int $storeSourceId, int $sourceOrderId): OrderVerification;

    /**
     * Record a change of state, under an optimistic lock, and keep the event.
     *
     * The state row and the history row are written in one transaction: a history that can disagree
     * with the state it describes is worse than no history at all.
     *
     * @param int|null $expectedVersion the version the writer was looking at; null waives the check,
     *                                  which is what a request carrying no version means
     *
     * @throws VerificationConflict when the stored version has moved on
     */
    public function apply(
        int $storeSourceId,
        int $sourceOrderId,
        VerificationAction $action,
        InitiatorType $actorType,
        int $actorId,
        ?int $expectedVersion,
        DateTimeImmutable $now,
    ): OrderVerification;
}
