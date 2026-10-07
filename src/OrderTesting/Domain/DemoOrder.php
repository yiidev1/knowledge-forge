<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use function trim;

/**
 * One demo order, normalised out of one JSON document the Order58 listener wrote.
 *
 * ## It is a snapshot, not a record
 *
 * The ids inside the document — customer, address, order item, reservation — are **not** keys into
 * anything this application or Order58's production database holds. The client was explicit about that,
 * and the importer never resolves them. The only two ids that mean anything are the pair in the filename,
 * and `account_id`, which names the store.
 *
 * ## What is a column and what is not
 *
 * Columns are the fields the list and the detail page read. Everything else stays in `raw_payload`, so a
 * field that turns out to matter becomes a read rather than a migration plus a rescan of files that may
 * by then be gone.
 *
 * Money is carried as **strings**, all the way from the document to the page. The source sends
 * `"4.28000"`; a float would make the totals that are the entire point of comparing two orders subtly
 * wrong.
 */
final readonly class DemoOrder
{
    /**
     * @param int|null $matchedAttemptId the local attempt row this order was credited to, or null when
     *                                   nothing was open when it arrived — shown as unmatched, never guessed
     */
    public function __construct(
        public int $storeSourceId,
        public int $sourceOrderId,
        public int $demoOrderId,
        public ?string $customerPhone,
        public ?string $customerFirstName,
        public ?string $customerLastName,
        public ?string $customerEmail,
        public ?string $orderType,
        public ?string $paymentMethod,
        public ?string $status,
        public ?string $subtotal,
        public ?string $shippingFee,
        public ?string $tax,
        public ?string $tip,
        public ?string $totalAmount,
        public ?string $address,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?int $orderCreatedAt,
        public ?int $orderUpdatedAt,
        public string $sourceFilename,
        public ?int $sourceMtime,
        public string $contentHash,
        /** The complete document, with payment secrets already removed. JSON-encoded. */
        public string $rawPayload,
        public ?int $matchedAttemptId = null,
    ) {}

    public function customerName(): ?string
    {
        $name = trim(($this->customerFirstName ?? '') . ' ' . ($this->customerLastName ?? ''));

        return $name === '' ? null : $name;
    }
}
