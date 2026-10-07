<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\NormalizedAddress;
use App\OrderTesting\Domain\Comparison\NormalizedCustomer;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\NormalizedTotals;
use App\OrderTesting\Domain\DemoOrder;

/**
 * A stored demo order, read back into the shape the comparison uses.
 *
 * It is {@see OrderNormalizer} over the stored payload, with **the address put back from the columns**.
 *
 * That one exception exists because `street1` is on the payment-secret list and is therefore stripped
 * from the stored payload at every depth — including from the shipping block, where Order58 reuses the
 * same key name for the delivery address. The importer reads the address out of the original document
 * *before* redaction and writes it to its own columns, and this is where those columns rejoin the
 * normalised object. Without it the detail page would show no delivery address for an order that
 * plainly had one.
 *
 * Nothing else is taken from the columns. They were written by the same normalizer from the same
 * document, so preferring them anywhere else would only add a second path for the same value to arrive
 * by — and a second path is a second thing that can disagree.
 */
final readonly class DemoOrderNormalizer
{
    public function __construct(private OrderNormalizer $normalizer) {}

    public function normalize(DemoOrder $order): NormalizedOrder
    {
        $fromPayload = $this->normalizer->fromJson($order->rawPayload);

        $address = new NormalizedAddress(
            street: $order->address,
            street2: $fromPayload?->address->street2,
            city: $order->city,
            state: $order->state,
            postalCode: $order->postalCode,
            destination: $fromPayload?->address->destination,
        );

        if ($fromPayload === null) {
            // A row whose payload will not decode is still a demo order that exists, and the columns
            // alone are enough to say so. Hiding it would lose a trainee's work over a storage fault.
            return new NormalizedOrder(
                orderId: $order->demoOrderId,
                customer: new NormalizedCustomer(
                    firstName: $order->customerFirstName,
                    lastName: $order->customerLastName,
                    phone: $order->customerPhone,
                    email: $order->customerEmail,
                ),
                orderType: $order->orderType,
                paymentMethod: $order->paymentMethod,
                status: $order->status,
                address: $address,
                totals: new NormalizedTotals(
                    subtotal: $order->subtotal,
                    shippingFee: $order->shippingFee,
                    tax: $order->tax,
                    tip: $order->tip,
                    total: $order->totalAmount,
                ),
                items: [],
                createdAt: $order->orderCreatedAt,
                updatedAt: $order->orderUpdatedAt,
            );
        }

        return new NormalizedOrder(
            orderId: $fromPayload->orderId ?? $order->demoOrderId,
            customer: $fromPayload->customer,
            orderType: $fromPayload->orderType,
            paymentMethod: $fromPayload->paymentMethod,
            status: $fromPayload->status,
            address: $address,
            totals: $fromPayload->totals,
            items: $fromPayload->items,
            createdAt: $fromPayload->createdAt,
            updatedAt: $fromPayload->updatedAt,
        );
    }
}
