<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\DemoOrderFileName;
use App\Shared\Order58\PaymentSecretRedactor;
use JsonException;

use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * Turns one demo-order JSON document into a row, or says exactly why it will not.
 *
 * ## Everything is checked; nothing is repaired
 *
 * A document that fails any gate is refused with a reason and the file is left untouched on disk. The
 * alternative — importing what could be read and nulling the rest — produces rows that look like demo
 * orders, are counted as demo orders, and are wrong in a way nobody can see from the page.
 *
 * The gates, in order:
 *
 *  1. the file parses as a JSON object;
 *  2. `is_test` is 1 — **a live order must never be imported by this feature**, and this is the second
 *     of the two defences, the first being that the importer only ever opens the demo directory;
 *  3. the document's own `id` equals the demo id in the filename, so a file renamed by hand cannot
 *     attribute one order's contents to another's number;
 *  4. `uid`, when present and parseable, begins with the source order id in the filename — the listener
 *     derives the filename from exactly that field, so a disagreement means one of the two is corrupt;
 *  5. `account_id` is a positive integer, because it is the store the row belongs to and a row filed
 *     under the wrong store would be visible on the wrong merchant's page.
 *
 * ## The fields come from the comparison normalizer, not from a second parser
 *
 * {@see OrderNormalizer} is the one reader of an Order58 order document, and the columns written here
 * are taken from what it produced. That is deliberate: the value stored and the value later compared are
 * then the same value, and a parsing fix lands on both at once.
 *
 * ## The address is read before the payload is redacted
 *
 * `street1` is on the payment-secret list and is stripped from the stored blob at every depth, including
 * from the shipping block where the key name is reused. The delivery address is read out of the original
 * document first and written to its own columns — a delivery address is ordinary order data and is the
 * single most useful thing on the detail page. See {@see PaymentSecretRedactor}.
 */
final readonly class DemoOrderDocument
{
    private function __construct(
        public ?DemoOrder $order,
        /** Operator-facing, and safe to log: it names the gate, never the contents. */
        public ?string $refusal = null,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    /**
     * @param string   $json  the file's contents, exactly as read
     * @param int|null $mtime the file's modification time, for diagnosis on a later scan
     */
    public static function parse(
        DemoOrderFileName $name,
        string $json,
        ?int $mtime,
        OrderNormalizer $normalizer,
    ): self {
        try {
            /** @var mixed $raw */
            $raw = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::refused('not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($raw)) {
            return self::refused('the document is not a JSON object');
        }

        if (OrderNormalizer::intOf($raw['is_test'] ?? null) !== 1) {
            return self::refused('is_test is not 1, so this is not a demo order');
        }

        if (OrderNormalizer::intOf($raw['id'] ?? null) !== $name->demoOrderId) {
            return self::refused('the document\'s id does not match the demo id in the filename');
        }

        $uidSource = self::uidSourceOrderId($raw['uid'] ?? null);
        if ($uidSource !== null && $uidSource !== $name->sourceOrderId) {
            return self::refused('the uid names a different source order than the filename does');
        }

        $accountId = OrderNormalizer::intOf($raw['account_id'] ?? null);
        if ($accountId === null || $accountId <= 0) {
            return self::refused('account_id is missing or not a store id');
        }

        // Normalised from the ORIGINAL document, so the address survives; redacted afterwards for
        // storage. The two steps are in this order on purpose and the order is the whole trick.
        $normalized = $normalizer->normalize($raw);
        $payload = json_encode(PaymentSecretRedactor::redact($raw), JSON_THROW_ON_ERROR);

        return new self(new DemoOrder(
            storeSourceId: $accountId,
            sourceOrderId: $name->sourceOrderId,
            demoOrderId: $name->demoOrderId,
            customerPhone: $normalized->customer->phone,
            customerFirstName: $normalized->customer->firstName,
            customerLastName: $normalized->customer->lastName,
            customerEmail: $normalized->customer->email,
            orderType: $normalized->orderType,
            paymentMethod: $normalized->paymentMethod,
            status: $normalized->status,
            subtotal: $normalized->totals->subtotal,
            shippingFee: $normalized->totals->shippingFee,
            tax: $normalized->totals->tax,
            tip: $normalized->totals->tip,
            totalAmount: $normalized->totals->total,
            address: $normalized->address->street,
            city: $normalized->address->city,
            state: $normalized->address->state,
            postalCode: $normalized->address->postalCode,
            orderCreatedAt: $normalized->createdAt,
            orderUpdatedAt: $normalized->updatedAt,
            sourceFilename: $name->basename,
            sourceMtime: $mtime,
            // Over the REDACTED payload, so a row written before the redactor existed reports itself
            // changed on the next scan and is rewritten clean. Hashing the raw file would freeze the
            // secrets in place behind an "unchanged" verdict.
            contentHash: hash('sha256', $payload),
            rawPayload: $payload,
        ));
    }

    /** The leading `SOURCEORDERID` of `SOURCEORDERID-PHONE-TIMESTAMP`, or null when unreadable. */
    private static function uidSourceOrderId(mixed $uid): ?int
    {
        if (!is_string($uid) || preg_match('/^([1-9][0-9]{0,18})-/', $uid, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
