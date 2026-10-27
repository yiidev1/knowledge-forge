<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

/**
 * What the day's one request produced.
 *
 * The **sanitized** body, not the one that arrived: the client redacts before returning, so nothing
 * downstream can stage, log or hash a payload with a card number in it. `decoded` is that same
 * sanitized document already parsed, handed over so the caller does not decode sixteen megabytes
 * twice.
 */
final readonly class CaseOrderResponse
{
    public function __construct(
        public int $httpStatus,
        /** Bytes as received, for the operator's record. The sanitized body may be shorter. */
        public int $responseBytes,
        /** Re-encoded after redaction. This is what gets staged. */
        public string $sanitizedBody,
        /** @var list<array<array-key, mixed>> the orders, sanitized */
        public array $orders,
    ) {}
}
