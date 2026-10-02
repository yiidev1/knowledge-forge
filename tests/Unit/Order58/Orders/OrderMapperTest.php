<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Orders;

use App\Order58\Application\Orders\OrderMapper;
use App\Order58\Application\Orders\OrderNotMappable;
use Codeception\Test\Unit;

use function json_encode;

/**
 * Mapping one order record. **No test here reaches a network or a database.**
 *
 * The fixtures are the client's own sample response, values unchanged, so what is pinned here is the
 * real contract rather than a convenient reading of it.
 */
final class OrderMapperTest extends Unit
{
    private const ACCOUNT = 1141;

    // ------------------------------------------------------------------ reservation phone

    /** The headline requirement: the phone lives inside a JSON-encoded *string*, not a nested object. */
    public function testThePhoneIsReadOutOfTheJsonEncodedDataString(): void
    {
        $order = (new OrderMapper())->toOrder(self::ACCOUNT, $this->record(['id' => '16693901']));

        $this->assertSame('13474472616', $order->reservationPhone);
    }

    /** The contract does not promise which shape arrives, so an already-decoded `data` works too. */
    public function testAnAlreadyDecodedDataObjectAlsoWorks(): void
    {
        $raw = $this->record([]);
        $raw['data'] = ['reservation' => ['phone' => '19177837797']];

        $this->assertSame('19177837797', (new OrderMapper())->toOrder(self::ACCOUNT, $raw)->reservationPhone);
    }

    /**
     * **`shipping.phone` is not `reservation.phone`.**
     *
     * They sit side by side and are usually identical, which is exactly what makes a silent fallback a
     * bug nobody notices until the one order where they differ.
     */
    public function testTheShippingPhoneIsNeverUsedAsAFallback(): void
    {
        $raw = $this->record([]);
        $raw['data'] = (string) json_encode([
            'shipping' => ['phone' => '15550000000'],
            'reservation' => ['first_name' => 'x'],
        ]);

        $this->assertNull((new OrderMapper())->toOrder(self::ACCOUNT, $raw)->reservationPhone);
    }

    /** A phone is a string. Casting would break a leading zero or `+`, and overflow on 32-bit. */
    public function testThePhoneStaysAStringAndKeepsItsShape(): void
    {
        $this->assertSame('+19177837797', OrderMapper::reservationPhone(
            (string) json_encode(['reservation' => ['phone' => '+19177837797']]),
        ));
        $this->assertSame('0017738889999', OrderMapper::reservationPhone(
            (string) json_encode(['reservation' => ['phone' => '0017738889999']]),
        ));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->assertSame('13474472616', OrderMapper::reservationPhone(
            (string) json_encode(['reservation' => ['phone' => "  13474472616\n"]]),
        ));
    }

    /**
     * Every way of saying nothing becomes null, which the repository reads as "leave what is stored".
     *
     * @dataProvider unusablePhones
     */
    public function testAnUnusablePhoneIsNull(mixed $data): void
    {
        $this->assertNull(OrderMapper::reservationPhone($data));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusablePhones(): array
    {
        return [
            'data absent' => [null],
            'data empty string' => [''],
            'data whitespace' => ['   '],
            'data not json' => ['{not json'],
            'no reservation key' => ['{"shipping":{"phone":"1555"}}'],
            'reservation not an object' => ['{"reservation":"nope"}'],
            'phone absent' => ['{"reservation":{"first_name":""}}'],
            'phone null' => ['{"reservation":{"phone":null}}'],
            'phone empty' => ['{"reservation":{"phone":""}}'],
            'phone numeric type' => ['{"reservation":{"phone":13474472616}}'],
            'phone is letters' => ['{"reservation":{"phone":"call me"}}'],
            'phone far too long' => ['{"reservation":{"phone":"1234567890123456789012345678901234567890"}}'],
            'data is a number' => [12345],
        ];
    }

    // ------------------------------------------------------------------ identity and money

    public function testIdentityComesFromTheRecordAndTheAccountFromTheRequest(): void
    {
        // The record's own account_id is deliberately a different store: the sync asked about ACCOUNT,
        // and filing the order anywhere else would be cross-store contamination.
        $raw = $this->record(['id' => '16693911', 'account_id' => '9999']);

        $order = (new OrderMapper())->toOrder(self::ACCOUNT, $raw);

        $this->assertSame(16693911, $order->sourceOrderId);
        $this->assertSame(self::ACCOUNT, $order->accountId);
    }

    public function testARecordWithoutAUsableIdIsRefused(): void
    {
        $this->expectException(OrderNotMappable::class);

        (new OrderMapper())->toOrder(self::ACCOUNT, $this->record(['id' => '']));
    }

    /** Money stays a string all the way to the DECIMAL column — a float would round `0.08875`. */
    public function testMoneyIsCarriedAsStringsWithoutRounding(): void
    {
        $order = (new OrderMapper())->toOrder(self::ACCOUNT, $this->record([]));

        $this->assertSame('18.42000', $order->money['total_amount']);
        $this->assertSame('0.08875', $order->money['tax_rate']);
        $this->assertSame('1.42000', $order->money['tax']);
    }

    public function testProviderTimestampsStayIntegers(): void
    {
        $order = (new OrderMapper())->toOrder(self::ACCOUNT, $this->record([]));

        $this->assertSame(1790914956, $order->sourceTimes['source_created_at']);
        $this->assertSame(1790914955, $order->sourceTimes['dining_time']);
        // The source sends null for an order that was never cancelled; it must not become 0.
        $this->assertNull($order->sourceTimes['cancelled_at']);
    }

    public function testTheWholeOriginalRecordIsPreserved(): void
    {
        $order = (new OrderMapper())->toOrder(self::ACCOUNT, $this->record([]));
        $payload = json_decode($order->payloadJson, true);

        // Including fields this application has no column for.
        $this->assertSame('T1790914956', $payload['last_print_label']);
        $this->assertSame('print success', $payload['print_status']);
        $this->assertArrayHasKey('commit_data', $payload);
    }

    // ------------------------------------------------------------------ change detection

    public function testTheHashIsStableForAnIdenticalRecord(): void
    {
        $mapper = new OrderMapper();

        $this->assertSame(
            $mapper->toOrder(self::ACCOUNT, $this->record([]))->contentHash,
            $mapper->toOrder(self::ACCOUNT, $this->record([]))->contentHash,
        );
    }

    /**
     * It moves for a change anywhere — including a field with no column of its own.
     *
     * @dataProvider changes
     *
     * @param array<string, mixed> $change
     */
    public function testTheHashMovesWhenAnythingChanges(array $change): void
    {
        $mapper = new OrderMapper();

        $this->assertNotSame(
            $mapper->toOrder(self::ACCOUNT, $this->record([]))->contentHash,
            $mapper->toOrder(self::ACCOUNT, $this->record($change))->contentHash,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changes(): array
    {
        return [
            'status' => [['status' => 'cancelled']],
            'total' => [['total_amount' => '19.99000']],
            'type' => [['type' => 'take-out']],
            // No column holds this one; the payload half of the hash is what catches it.
            'an untyped field' => [['last_print_total' => '7']],
        ];
    }

    /** Two different stores' copies of the same order id are different records. */
    public function testTheHashIsAccountSpecific(): void
    {
        $mapper = new OrderMapper();

        $this->assertNotSame(
            $mapper->toOrder(self::ACCOUNT, $this->record([]))->contentHash,
            $mapper->toOrder(2222, $this->record([]))->contentHash,
        );
    }

    /**
     * One order from the client's sample response, values unchanged.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function record(array $overrides): array
    {
        $data = (string) json_encode([
            'cc' => null,
            'reservation' => [
                'id' => 17001141,
                'first_name' => '',
                'phone' => '13474472616',
                'dine_type' => 'take-out',
                'order_id' => 16693901,
            ],
            'shipping' => null,
            'payment_method' => 'cash',
            'total_amount' => 7.62,
        ]);

        return $overrides + [
            'id' => '16693911',
            'seq' => 'AM2#1',
            'transaction_id' => '0',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'account_id' => '1141',
            'user_id' => '2521',
            'customer_id' => '4035391',
            'seawolf_subtotal' => '16.00000',
            'total_cost' => '16.00000',
            'discount' => '0.00000',
            'created_at' => '1790914956',
            'updated_at' => '1790914956',
            'address_id' => '17361161',
            'status' => 'completed',
            'total_amount' => '18.42000',
            'shipping_fee' => '1.00000',
            'delivery_id' => '591',
            'tax' => '1.42000',
            'tax_rate' => '0.08875',
            'service_fee' => '0.00000',
            'surcharge' => '0.00000',
            'tip' => '0.00000',
            'uid' => '1141202610020420335370001',
            'dining_time' => '1790914955',
            'call_id' => '4294501',
            'print_status' => 'print success',
            'data' => $data,
            'commit_data' => $data,
            'frontend' => '0',
            'is_test' => '0',
            'last_print_label' => 'T1790914956',
            'last_print_total' => '2',
            'timezone' => 'America/New_York',
            'paid' => '0',
            'cancelled_at' => null,
            'pickup_delivery_at' => '01:05 AM',
        ];
    }
}
