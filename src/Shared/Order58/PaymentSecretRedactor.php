<?php

declare(strict_types=1);

namespace App\Shared\Order58;

use JsonException;

use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Removes payment secrets from an Order58 order document before anything stores it.
 *
 * ## Why this is not optional
 *
 * The provider sends the card verification value in clear text, inside `data.cc` and again at the top
 * level of `data`, for every credit-card order. **A CVV must never be persisted after authorisation** —
 * PCI DSS 3.2 prohibits it outright, with no encryption or compensating control that makes it
 * permissible. The expiry and the card number travel beside it and have no business in a training tool's
 * database either.
 *
 * A demo order is not exempt. It is a real document written by the real listener from a real Order58
 * submission, and the operator typing it is being *trained* to enter card details — so the demo
 * directory is, if anything, more likely to hold them than the live one.
 *
 * ## Why this is a second copy, and how it is kept honest
 *
 * `App\Order58\Application\Orders\OrderMapper` has carried this rule since the orders mirror was built,
 * as a private method. Importing it here would couple Order Testing to the sync module for five strings,
 * and extracting it from there would edit a file in the live sync path for no behavioural reason.
 *
 * So the list is stated twice — and `PaymentSecretRedactorTest` asserts the two are identical by
 * reflection, so a key added to one and not the other fails the build rather than quietly leaking from
 * whichever copy was forgotten.
 *
 * ## `street1` is in the list, and the delivery address is still shown
 *
 * `street1` is the **billing** street that travels with the card. It is stripped from the stored blob at
 * every depth, including from the shipping block where the same key name is reused. The delivery address
 * an operator needs to see is therefore read out of the document and written to its own columns *before*
 * redaction — a delivery address is ordinary order data, a billing address beside a PAN is not.
 */
final readonly class PaymentSecretRedactor
{
    /**
     * Keys removed from every stored payload, at every depth.
     *
     * `cvv` is the one that is prohibited rather than merely unwise. The others are kept out because a
     * tool that never charges a card has no reason to hold one.
     */
    public const PAYMENT_SECRETS = ['cvv', 'card_num', 'exp_date', 'billing_zip', 'street1'];

    /** Record fields that are themselves JSON-encoded strings, so the secrets sit one level down. */
    public const NESTED_JSON = ['data', 'commit_data'];

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $raw): array
    {
        foreach (self::NESTED_JSON as $key) {
            $value = $raw[$key] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }

            // `data` and `commit_data` are JSON-encoded STRINGS inside the record, so the secrets are a
            // level down and have to be decoded to be removed. A value that does not decode to an array
            // is left exactly as it was rather than replaced with something this guessed at.
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $raw[$key] = json_encode(self::strip($decoded), JSON_THROW_ON_ERROR);
        }

        return self::strip($raw);
    }

    /**
     * Every occurrence, at every depth.
     *
     * Recursive because the same values appear in more than one place in one record — inside `cc`, and
     * again as siblings of it — and a rule that removed only the ones seen in a sample would quietly
     * keep whichever copy the next document added.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function strip(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::PAYMENT_SECRETS, true)) {
                continue;
            }

            $clean[$key] = is_array($item) ? self::strip($item) : $item;
        }

        return $clean;
    }
}
