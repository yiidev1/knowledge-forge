<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use App\Order58\Domain\Orders\Order58Order;

use function hash;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Turns one API order record into a {@see Order58Order}.
 *
 * Nothing here validates the *shape* of the response beyond what it needs; an unknown extra field is
 * kept verbatim in the payload and ignored by the typed columns, which is what makes a provider adding
 * a field a non-event rather than a migration.
 */
final class OrderMapper
{
    /** Money columns, each read as a string and kept as one. */
    private const MONEY = [
        'seawolf_subtotal', 'seawolf_cost', 'total_cost', 'discount', 'total_amount',
        'shipping_fee', 'tax', 'tax_rate', 'service_fee', 'surcharge', 'tip',
    ];

    /** Provider Unix timestamps, column name => source key. */
    private const TIMES = [
        'source_created_at' => 'created_at',
        'source_updated_at' => 'updated_at',
        'dining_time' => 'dining_time',
        'cancelled_at' => 'cancelled_at',
    ];

    /**
     * @param array<array-key, mixed> $raw
     *
     * @throws OrderNotMappable when the record carries no usable identity
     */
    public function toOrder(int $accountId, array $raw): Order58Order
    {
        $sourceOrderId = $this->id($raw['id'] ?? null);
        if ($sourceOrderId === null) {
            throw new OrderNotMappable('an order arrived without a usable "id"');
        }

        // The account is taken from the request, not the record. A response that disagreed with the
        // account we asked about would otherwise file one store's order under another's.
        $money = [];
        foreach (self::MONEY as $column) {
            $money[$column] = $this->decimal($raw[$column] ?? null);
        }

        $times = [];
        foreach (self::TIMES as $column => $key) {
            $times[$column] = $this->timestamp($raw[$key] ?? null);
        }

        $payloadJson = json_encode($raw, JSON_THROW_ON_ERROR);

        $order = [
            'accountId' => $accountId,
            'sourceOrderId' => $sourceOrderId,
            'uid' => $this->text($raw['uid'] ?? null, 64),
            'seq' => $this->text($raw['seq'] ?? null, 32),
            'customerId' => $this->id($raw['customer_id'] ?? null),
            'userId' => $this->id($raw['user_id'] ?? null),
            'transactionId' => $this->text($raw['transaction_id'] ?? null, 64),
            'type' => $this->text($raw['type'] ?? null, 32),
            'paymentMethod' => $this->text($raw['payment_method'] ?? null, 32),
            'status' => $this->text($raw['status'] ?? null, 32),
            'isTest' => $this->flag($raw['is_test'] ?? null),
            'frontend' => $this->flag($raw['frontend'] ?? null),
            'paid' => $this->flag($raw['paid'] ?? null),
            'reservationPhone' => self::reservationPhone($raw['data'] ?? null),
            'money' => $money,
            'promotionCode' => $this->text($raw['promotion_code'] ?? null, 64),
            'addressId' => $this->id($raw['address_id'] ?? null),
            'deliveryId' => $this->id($raw['delivery_id'] ?? null),
            'callId' => $this->id($raw['call_id'] ?? null),
            'printStatus' => $this->text($raw['print_status'] ?? null, 32),
            'pickupDeliveryAt' => $this->text($raw['pickup_delivery_at'] ?? null, 32),
            'timezone' => $this->text($raw['timezone'] ?? null, 64),
            'sourceTimes' => $times,
        ];

        return new Order58Order(
            accountId: $accountId,
            sourceOrderId: $sourceOrderId,
            uid: $order['uid'],
            seq: $order['seq'],
            customerId: $order['customerId'],
            userId: $order['userId'],
            transactionId: $order['transactionId'],
            type: $order['type'],
            paymentMethod: $order['paymentMethod'],
            status: $order['status'],
            isTest: $order['isTest'],
            frontend: $order['frontend'],
            paid: $order['paid'],
            reservationPhone: $order['reservationPhone'],
            money: $money,
            promotionCode: $order['promotionCode'],
            addressId: $order['addressId'],
            deliveryId: $order['deliveryId'],
            callId: $order['callId'],
            printStatus: $order['printStatus'],
            pickupDeliveryAt: $order['pickupDeliveryAt'],
            timezone: $order['timezone'],
            sourceTimes: $times,
            payloadJson: $payloadJson,
            contentHash: self::hashOf($order, $payloadJson),
        );
    }

    /**
     * The fingerprint change detection compares.
     *
     * Covers both the mapped fields and the raw payload, so a change anywhere in the record counts —
     * including in a field this application does not yet have a column for.
     *
     * The source's own `updated_at` is deliberately **not** trusted for this: the client's sample
     * contains orders where `created_at` and `updated_at` are the same second, so it demonstrably does
     * not move whenever something else does.
     *
     * @param array<string, mixed> $mapped
     */
    private static function hashOf(array $mapped, string $payloadJson): string
    {
        return hash('sha256', json_encode($mapped, JSON_THROW_ON_ERROR) . "\0" . $payloadJson);
    }

    /**
     * `reservation.phone`, out of the JSON-encoded `data` **string**.
     *
     * Three things this gets right that an obvious implementation does not:
     *
     *  - `data` arrives as a *string* containing JSON, not as a nested object, so it is decoded here. An
     *    already-decoded array is accepted too, because the contract does not promise which.
     *  - The value stays a **string**. Casting would turn `"19177837797"` into a float on 32-bit builds
     *    and would eat a leading `0` or `+` anywhere.
     *  - It reads `reservation.phone` and nothing else. `shipping.phone` sits beside it and is often the
     *    same number, which is exactly what makes silently falling back to it a bug nobody notices.
     *
     * Null means the source gave nothing usable, which the repository treats as "leave what is stored".
     */
    public static function reservationPhone(mixed $data): ?string
    {
        if (is_string($data)) {
            if (trim($data) === '') {
                return null;
            }

            /** @var mixed $data */
            $data = json_decode($data, true);
        }

        if (!is_array($data) || !isset($data['reservation']) || !is_array($data['reservation'])) {
            return null;
        }

        $phone = $data['reservation']['phone'] ?? null;

        if (!is_string($phone)) {
            return null;
        }

        $phone = trim($phone);

        // A phone, not an arbitrary string: digits with an optional leading `+`, and short enough for the
        // column. Anything else is declined rather than truncated into something that looks real.
        return preg_match('/^\+?[0-9][0-9 .()-]{2,30}$/', $phone) === 1 ? $phone : null;
    }

    private function id(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,19}$/', $value) === 1) {
            return (int) $value;
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** Kept as a string: the column is DECIMAL and a float round-trip is how precision is lost. */
    private function decimal(mixed $value): ?string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function timestamp(mixed $value): ?int
    {
        $id = $this->id($value);

        return $id === 0 ? null : $id;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function flag(mixed $value): bool
    {
        return $value === 1 || $value === '1' || $value === true;
    }
}
