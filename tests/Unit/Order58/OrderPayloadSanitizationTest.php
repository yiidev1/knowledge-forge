<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Orders\OrderMapper;
use Codeception\Test\Unit;

use function json_decode;
use function json_encode;
use function str_contains;

/**
 * Payment secrets never reach `payload_json`.
 *
 * ## Why this is a test and not a comment
 *
 * Order58 sends the card verification value in clear text, in two places in every credit-card order, and
 * `payload_json` was storing the record verbatim. **A CVV may not be persisted after authorisation** —
 * PCI DSS 3.2 prohibits it outright, and no encryption or compensating control makes it permissible.
 *
 * It was safe to remove because `payload_json` is write-only in this application: the orders list selects
 * named columns, the demo-link reader selects named columns, and nothing reads the blob back. This test
 * pins both halves — the secrets are gone, and everything else survives.
 *
 * No real card value appears here. The markers below are invented strings chosen so a failure can be
 * read without printing anything that would matter if it were real.
 */
final class OrderPayloadSanitizationTest extends Unit
{
    private const SECRET_KEYS = ['cvv', 'card_num', 'exp_date', 'billing_zip', 'street1'];

    /** The shape the provider actually sends: secrets inside `cc` AND repeated beside it. */
    private function rawOrder(): array
    {
        $data = [
            'cc' => [
                'card_num' => 'MARKER-PAN',
                'exp_date' => 'MARKER-EXP',
                'cvv' => 'MARKER-CVV',
                'street1' => 'MARKER-STREET',
                'billing_zip' => 'MARKER-ZIP',
            ],
            // The duplicates. A rule that only cleaned `cc` would leave every one of these behind.
            'card_num' => 'MARKER-PAN',
            'exp_date' => 'MARKER-EXP',
            'cvv' => 'MARKER-CVV',
            'payment_method' => 'credit card',
            'total_amount' => 30.33,
            'reservation' => ['id' => 16951411, 'order_id' => 16642891, 'phone' => '15550000000'],
            'shipping' => ['city' => 'Freeport', 'postal_code' => '11520'],
        ];

        return [
            'id' => '16642891',
            'account_id' => '1731',
            'type' => 'delivery',
            'payment_method' => 'credit card',
            'status' => 'completed',
            'total_amount' => '30.33000',
            'created_at' => '1790699437',
            // Both of them, because the provider repeats the whole object and both are stored.
            'data' => json_encode($data),
            'commit_data' => json_encode($data),
        ];
    }

    public function testNoPaymentSecretSurvivesIntoTheStoredPayload(): void
    {
        $order = (new OrderMapper())->toOrder(1731, $this->rawOrder());

        foreach (self::SECRET_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $order->payloadJson,
                'the key "' . $key . '" must not be stored',
            );
        }

        // And the values themselves, in case a key is ever renamed rather than removed.
        foreach (['MARKER-CVV', 'MARKER-PAN', 'MARKER-EXP', 'MARKER-ZIP', 'MARKER-STREET'] as $value) {
            $this->assertStringNotContainsString($value, $order->payloadJson);
        }
    }

    /** The nested copies go too — `data` and `commit_data` are JSON strings, so they decode first. */
    public function testTheSecretsAreRemovedFromBothNestedCopies(): void
    {
        $order = (new OrderMapper())->toOrder(1731, $this->rawOrder());
        $payload = json_decode($order->payloadJson, true);

        foreach (['data', 'commit_data'] as $key) {
            $this->assertIsString($payload[$key] ?? null, $key . ' is still a JSON string');
            $this->assertFalse(str_contains((string) $payload[$key], 'MARKER-CVV'));
            $this->assertFalse(str_contains((string) $payload[$key], 'cvv'));
        }
    }

    /** Everything the application might ever want out of the record is still there. */
    public function testNothingElseIsLost(): void
    {
        $order = (new OrderMapper())->toOrder(1731, $this->rawOrder());
        $payload = json_decode($order->payloadJson, true);

        $this->assertSame('16642891', $payload['id']);
        $this->assertSame('credit card', $payload['payment_method']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('30.33000', $payload['total_amount']);

        $data = json_decode((string) $payload['data'], true);

        $this->assertSame(16951411, $data['reservation']['id'], 'the reservation survives');
        $this->assertSame('Freeport', $data['shipping']['city'], 'the delivery address survives');
        $this->assertSame('credit card', $data['payment_method']);
        // The `cc` object loses its contents but the record still records that a card was used, via
        // payment_method. Nothing here tries to keep a masked PAN, because nothing displays one.
        $this->assertSame([], $data['cc'] ?? []);
    }

    /** An order with no card data at all is untouched — the common case must not be disturbed. */
    public function testACashOrderIsUnchanged(): void
    {
        $raw = [
            'id' => '16642321',
            'payment_method' => 'cash',
            'data' => json_encode(['reservation' => ['id' => 1], 'payment_method' => 'cash']),
        ];

        $order = (new OrderMapper())->toOrder(1731, $raw);
        $payload = json_decode($order->payloadJson, true);

        $this->assertSame('cash', $payload['payment_method']);
        $this->assertSame(1, json_decode((string) $payload['data'], true)['reservation']['id']);
    }

    /** A `data` value that is not JSON is left exactly as it was rather than replaced with a guess. */
    public function testAnUndecodableNestedValueIsLeftAlone(): void
    {
        $order = (new OrderMapper())->toOrder(1731, ['id' => '1', 'data' => 'not json at all']);
        $payload = json_decode($order->payloadJson, true);

        $this->assertSame('not json at all', $payload['data']);
    }
}
