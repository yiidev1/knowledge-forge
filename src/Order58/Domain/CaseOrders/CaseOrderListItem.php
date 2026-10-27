<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

use DateTimeImmutable;

/**
 * One row of the admin Case Orders listing.
 *
 * Every field is read from a column or derived in SQL. Nothing is guessed: where the data does not
 * exist the field is null and the page prints a dash, which is the honest answer and a different one
 * from zero.
 *
 * `orderedAtUnix` and `lastSyncedAt` are deliberately separate. One is when the customer placed the
 * order, the other when this application last stored it — confusing them is the mistake this listing
 * exists to avoid, because they can be weeks apart on a rolling window.
 */
final readonly class CaseOrderListItem
{
    public function __construct(
        public int $storeSourceId,
        public int $sourceOrderId,
        public ?string $storeName,
        public ?string $seq,
        public ?string $status,
        public ?string $type,
        public ?string $totalAmount,
        public ?int $orderedAtUnix,
        public string $itemsStatus,
        public int $itemCount,
        public string $totalQuantity,
        public ?DateTimeImmutable $firstImportedAt,
        public ?DateTimeImmutable $lastSyncedAt,
        public bool $hasRecording,
        public bool $hasDemoOrder,
    ) {}

    public function orderedAt(): ?DateTimeImmutable
    {
        return $this->orderedAtUnix !== null && $this->orderedAtUnix > 0
            ? new DateTimeImmutable('@' . $this->orderedAtUnix)
            : null;
    }
}
