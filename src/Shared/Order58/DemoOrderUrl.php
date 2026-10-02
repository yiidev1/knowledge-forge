<?php

declare(strict_types=1);

namespace App\Shared\Order58;

use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_ends_with;
use function strtolower;
use function trim;

/**
 * Builds the Order58 demo-order link for one order, or says precisely why it cannot.
 *
 * `https://{host}/admin/demo/order/make/{orderId}-{phone}`
 *
 * In `App\Shared` rather than inside a module because both Audio-to-Text and Order58 need it and
 * `ModuleIsolationTest` forbids either from naming the other. This is the one definition; a second one
 * would eventually disagree, and the disagreement would be a link pointing at the wrong store.
 *
 * ## The host is checked against a list, not merely tidied
 *
 * `order58_stores.host` is a value a third party controls. Rendered into an `href` unchecked it is an
 * open redirect with this application's name on it — and the destination carries a customer's phone
 * number in its path, so an attacker-chosen host would be handed that phone by the browser.
 *
 * So a host must be a bare hostname **under one of the registrable domains this application actually
 * serves**. Both are real: 230 of the mirrored stores sit on `order58.com` and 5 on `17ip.com`, so a
 * naive "must end in order58.com" rule would silently break those five. Nothing else is accepted, and
 * nothing is repaired — a value carrying a scheme, a path, a port, credentials or whitespace is refused
 * rather than trimmed into something that merely looks safe.
 *
 * ## Nothing is built from partial data
 *
 * Every branch returns a {@see DemoLinkStatus} instead of a half-formed URL. A link missing its phone
 * would still be clickable and would still look like it worked.
 */
final readonly class DemoOrderUrl
{
    /**
     * Registrable domains this application will link to.
     *
     * An allow-list because the alternative is trusting a mirrored column with an `href`. Extend it only
     * when a store genuinely moves — a wildcard here is the open redirect this exists to prevent.
     */
    public const TRUSTED_DOMAINS = ['order58.com', '17ip.com'];

    private function __construct(
        public DemoLinkStatus $status,
        /** The absolute URL, only ever set when the status is Ready. */
        public ?string $url = null,
    ) {}

    public static function unavailable(DemoLinkStatus $status): self
    {
        return new self($status);
    }

    /**
     * The link for one order, from values already read out of the database.
     *
     * @param string|null $host      `order58_stores.host`
     * @param int|null    $orderId   `order58_orders.source_order_id`
     * @param string|null $phone     `order58_orders.reservation_phone`
     */
    public static function for(?string $host, ?int $orderId, ?string $phone): self
    {
        if ($orderId === null || $orderId <= 0) {
            return new self(DemoLinkStatus::OrderNotFound);
        }

        $host = self::trustedHost($host);
        if ($host === null) {
            return new self(DemoLinkStatus::HostUnavailable);
        }

        $phone = self::phone($phone);
        if ($phone === null) {
            return new self(DemoLinkStatus::PhoneUnavailable);
        }

        // Both components are already constrained to digits (and an optional leading `+` on the phone),
        // so encoding changes nothing today. It is here because the day one of them stops being digits
        // is the day an unencoded `/` would silently repoint the path.
        return new self(DemoLinkStatus::Ready, sprintf(
            'https://%s/admin/demo/order/make/%s-%s',
            $host,
            rawurlencode((string) $orderId),
            rawurlencode($phone),
        ));
    }

    public function isReady(): bool
    {
        return $this->status === DemoLinkStatus::Ready && $this->url !== null;
    }

    /**
     * A bare hostname under a trusted domain, lowercased — or null.
     *
     * Refuses, rather than strips: `https://x.order58.com`, `x.order58.com/evil`, `x.order58.com:8443`,
     * `user@x.order58.com`, `x.order58.com.attacker.net` and anything carrying whitespace or control
     * characters all return null. The suffix check requires a leading dot so `notorder58.com` cannot
     * pass by ending in the right letters.
     */
    private static function trustedHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(trim($host));

        if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,253}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        foreach (self::TRUSTED_DOMAINS as $domain) {
            // The leading dot matters: without it `notorder58.com` would be trusted.
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $host;
            }
        }

        return null;
    }

    /**
     * The phone as stored, or null.
     *
     * Kept a string throughout: a leading `0` or `+` is part of the number, and the demo page is handed
     * exactly what Order58 recorded. Nothing is reformatted — this is an identifier here, not a number
     * to display.
     */
    private static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        return preg_match('/^\+?[0-9]{3,20}$/', $phone) === 1 ? $phone : null;
    }
}
