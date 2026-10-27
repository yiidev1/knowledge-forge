<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Orders\OrderMapper;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The existing order mapper, fed a Case Order document.
 *
 * ## Why this test exists before any new mapping code
 *
 * The Case Order endpoint replaces two older ones, and the obvious reading of "replace the
 * integration" is to write a new mapper for it. That would be a second definition of what an order
 * is — a second money list, a second redaction pass, a second content hash — and the two would drift
 * the first time a column was added to one of them.
 *
 * The top level of a case order is the shape the existing mapper already reads; the endpoint's
 * novelty is the nested `items`, which is additive. So this asserts the reuse is sound *before* it is
 * relied upon, rather than assuming it: if the provider ever changes the order half, this fails here
 * and the new mapping can be introduced deliberately.
 */
final class CaseOrderMapperReuseTest extends TestCase
{
    /** @return array<array-key, mixed> the first order of the sanitized sample */
    private function firstOrder(): array
    {
        /** @var list<array<array-key, mixed>> $sample */
        $sample = json_decode(
            (string) file_get_contents(__DIR__ . '/../../_data/order58/case-orders-sample.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $sample[0];
    }

    /** The existing mapper reads a case order without being changed for it. */
    public function testTheExistingMapperReadsACaseOrder(): void
    {
        $order = (new OrderMapper())->toOrder(1121, $this->firstOrder());

        self::assertSame(1121, $order->accountId);
        self::assertSame(17066031, $order->sourceOrderId);
        self::assertSame('23#1', $order->seq);
        self::assertSame('delivery', $order->type);
        self::assertSame('credit card', $order->paymentMethod);
        self::assertSame('completed', $order->status);
        self::assertFalse($order->isTest);
    }

    /** Money keeps its exact decimal string, through the mapper this application already trusts. */
    public function testMoneyIsMappedExactly(): void
    {
        $order = (new OrderMapper())->toOrder(1121, $this->firstOrder());

        self::assertSame('36.63000', $order->money['total_amount']);
        self::assertSame('4.99000', $order->money['shipping_fee']);
        self::assertSame('1.79100', $order->money['tax']);
    }

    /** The customer's number comes out of the nested reservation, as it does for legacy orders. */
    public function testThePhoneIsFoundInTheNestedReservation(): void
    {
        self::assertSame(
            '15550000000',
            (new OrderMapper())->toOrder(1121, $this->firstOrder())->reservationPhone,
        );
    }

    /**
     * **The mapper's own redaction covers the case order's card data too.**
     *
     * The stored payload is what this asserts, because that is what reaches the database. The
     * documents carry the secrets twice — at the top of `data` and again inside `data.cc` — and
     * neither may survive.
     */
    public function testNoCardDataSurvivesTheExistingRedaction(): void
    {
        $order = (new OrderMapper())->toOrder(1121, $this->firstOrder());

        foreach (['cvv', 'card_num', 'exp_date'] as $secret) {
            self::assertStringNotContainsString($secret . '":"', $order->payloadJson, $secret);
        }

        self::assertStringNotContainsString('PLACEHOLDER-NOT-REAL', $order->payloadJson);
    }

    /**
     * The nested `items` are carried in the payload and ignored by the order mapping.
     *
     * That is the division of labour the import relies on: this mapper owns the order, and the case
     * order parser owns the lines. Neither needs to know how the other works.
     */
    public function testItemsAreToleratedAndNotMistakenForOrderFields(): void
    {
        $order = (new OrderMapper())->toOrder(1121, $this->firstOrder());

        // Present in the stored document…
        self::assertStringContainsString('Steak & Cheese', $order->payloadJson);
        // …and the order's own fields are unaffected by them.
        self::assertSame(17066031, $order->sourceOrderId);
        self::assertSame('36.63000', $order->money['total_amount']);
    }
}
