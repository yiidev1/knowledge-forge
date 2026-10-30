<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use App\OrderTesting\Domain\Comparison\NormalizedOrder;

use function is_numeric;
use function str_contains;
use function strtolower;
use function trim;

/**
 * What the comparison and receipt pages may print about how an order was paid — and the one place that
 * decides it, so the two pages cannot describe the same payment differently.
 *
 * ## Only what is safe to print exists to print
 *
 * The card is its last four digits, its expiry and its billing postcode — the three facts kept when the
 * order was stored, and nothing else. **The card number and the verification code are removed before an
 * order is saved and can never reach these pages**, and the billing street is removed with them. See
 * `App\Shared\Order58\PaymentSecretRedactor`.
 *
 * The expiry is cardholder data, which PCI DSS permits retaining. The verification code is authentication
 * data, which it does not — so there is no field, no getter and no row for it anywhere below.
 *
 * ## Both pages show the same rows on both sides
 *
 * Each page lays its two orders out row for row, so whether a row appears is decided from BOTH orders
 * here, once, and each side then prints its own value or a dash. A row decided per side would slide one
 * document out of step with the other.
 *
 * Nothing here is scored. The payment method row keeps the verdict the comparison gave it; the card and
 * the processing fee are shown for reference only.
 */
final class PaymentDisplay
{
    /** Printed for a card payment whose last four digits were not kept. */
    public const NOT_RECORDED = 'Card not recorded';

    /** Whether a payment method is a card payment, however the provider spelled it. */
    public static function isCard(?string $method): bool
    {
        return $method !== null && str_contains(strtolower($method), 'card');
    }

    /**
     * The card on one order as it may be printed: `•••• 1234`, {@see NOT_RECORDED}, or null when the
     * order was not paid by card.
     */
    public static function card(?NormalizedOrder $order): ?string
    {
        if ($order === null) {
            return null;
        }

        if ($order->cardLastFour !== null) {
            return '•••• ' . $order->cardLastFour;
        }

        return self::isCard($order->paymentMethod) ? self::NOT_RECORDED : null;
    }

    /** Whether either order has a card to describe. */
    public static function showsCard(?NormalizedOrder $original, ?NormalizedOrder $demo): bool
    {
        return self::card($original) !== null || self::card($demo) !== null;
    }

    /**
     * The card's expiry on one order as it may be printed, or null.
     *
     * Unlike {@see card()} there is no "not recorded" stand-in: a card order whose expiry was not kept
     * prints a dash like any other absent value, because an expiry is not the thing an operator is
     * checking the receipt for and a label claiming it is missing would be noise.
     */
    public static function expiry(?NormalizedOrder $order): ?string
    {
        return $order?->expiryDate;
    }

    /** Whether either order kept an expiry to show. */
    public static function showsExpiry(?NormalizedOrder $original, ?NormalizedOrder $demo): bool
    {
        return self::expiry($original) !== null || self::expiry($demo) !== null;
    }

    /**
     * The billing postcode on one order, or null.
     *
     * The **billing** postcode that travels with the card, not the delivery address — that has its own
     * section and is compared there. This is shown for reference and scored by nothing.
     */
    public static function billingZip(?NormalizedOrder $order): ?string
    {
        return $order?->billingZip;
    }

    /** Whether either order kept a billing postcode. Usually neither did. */
    public static function showsBillingZip(?NormalizedOrder $original, ?NormalizedOrder $demo): bool
    {
        return self::billingZip($original) !== null || self::billingZip($demo) !== null;
    }

    /**
     * Whether either order was paid by card or charged a processing fee.
     *
     * A missing fee and a fee of `0.00000` both mean "no fee", so a cash order on both sides prints no
     * fee row at all rather than a row of zeroes.
     */
    public static function showsFee(?NormalizedOrder $original, ?NormalizedOrder $demo): bool
    {
        foreach ([$original, $demo] as $order) {
            if ($order === null) {
                continue;
            }

            if (self::isCard($order->paymentMethod) || self::charged($order->totals->processingFee)) {
                return true;
            }
        }

        return false;
    }

    private static function charged(?string $amount): bool
    {
        return $amount !== null && is_numeric(trim($amount)) && (float) trim($amount) > 0;
    }
}
