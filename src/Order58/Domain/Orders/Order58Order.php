<?php

declare(strict_types=1);

namespace App\Order58\Domain\Orders;

/**
 * One mirrored Order58 order, ready to persist.
 *
 * Every money field is a **string**, not a float. The source sends `"18.42000"` and the column is
 * `DECIMAL(12,5)`; passing the value through PHP's float type on the way between them is how `0.08875`
 * becomes `0.088749999` and every total derived from it drifts. Nothing here does arithmetic, so there
 * is no reason for these ever to stop being strings.
 *
 * `payloadJson` is the complete original record. `contentHash` is computed over the mapped fields and is
 * the only thing change detection consults — see {@see \App\Order58\Application\Orders\OrderMapper}.
 */
final readonly class Order58Order
{
    /**
     * @param array<string, string|null> $money       keyed by column name
     * @param array<string, int|null>    $sourceTimes keyed by column name, provider Unix timestamps
     */
    public function __construct(
        public int $accountId,
        public int $sourceOrderId,
        public ?string $uid,
        public ?string $seq,
        public ?int $customerId,
        public ?int $userId,
        public ?string $transactionId,
        public ?string $type,
        public ?string $paymentMethod,
        public ?string $status,
        public bool $isTest,
        public bool $frontend,
        public bool $paid,
        /** Null means the source gave nothing usable — never "this order has no phone". */
        public ?string $reservationPhone,
        public array $money,
        public ?string $promotionCode,
        public ?int $addressId,
        public ?int $deliveryId,
        public ?int $callId,
        public ?string $printStatus,
        public ?string $pickupDeliveryAt,
        public ?string $timezone,
        public array $sourceTimes,
        public string $payloadJson,
        public string $contentHash,
    ) {}
}
