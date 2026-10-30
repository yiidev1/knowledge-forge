<?php

declare(strict_types=1);

namespace App\Shared\Order58;

use JsonException;

use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;

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
 * ## The last four digits are the one thing kept, and only when they can be proved
 *
 * The provider writes the card reference as `<encrypted token>|<last four digits>`, in `data.card_num` and
 * again in `data.cc.card_num`. Measured 2026-10-30 on 29 credit-card orders of a real Case Order response:
 * every one had that shape, both copies were identical, and on the three that could be checked the four
 * digits were the last four printed on Order58's own receipt for that order.
 *
 * {@see cardLastFour()} reads those four digits and nothing else — the token is never decoded, never
 * stored and never examined beyond its shape. A reference in any other shape, or two copies that
 * disagree, yields nothing rather than a guess. The digits are kept under {@see CARD_LAST_FOUR} by the
 * callers that store an order; every secret is still removed exactly as before.
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
     * Where a stored order keeps its card's last four digits — derived here, never sent by the provider.
     *
     * Top level of the record rather than inside `data`, because `data` is the provider's own structure
     * and this is not something the provider wrote there.
     */
    public const CARD_LAST_FOUR = 'card_last4';

    /**
     * `<token>|<four digits>`. The token must be more than a few characters, must not contain the
     * separator or whitespace, and must not be all digits — a run of digits there could be a card
     * number written in clear, which is a different document from the one this rule was measured on.
     */
    private const CARD_REFERENCE = '/^(?!\d+\|)[^|\s]{8,}\|(\d{4})$/';

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
     * {@see redact()}, plus the card's last four digits when {@see cardLastFour()} can prove them.
     *
     * For an ORDER document about to be stored. Line items and envelopes use {@see redact()} alone: they
     * carry no card reference, and a key appearing on them would be one more place a card is described.
     *
     * @param array<array-key, mixed> $raw the provider's order, before redaction
     *
     * @return array<array-key, mixed>
     */
    public static function redactKeepingCardLastFour(array $raw): array
    {
        $clean = self::redact($raw);
        $lastFour = self::cardLastFour($raw);

        // Whatever arrived under this name is replaced by what was proved here, or removed.
        unset($clean[self::CARD_LAST_FOUR]);

        if ($lastFour !== null) {
            $clean[self::CARD_LAST_FOUR] = $lastFour;
        }

        return $clean;
    }

    /**
     * The last four digits of the card on an order, or null when they cannot be proved.
     *
     * Read from `data.card_num` and `data.cc.card_num`, whichever are present. Every copy present must
     * have the measured shape and all must agree; one malformed or disagreeing copy and the answer is
     * null. A record whose reference was already redacted is asked for the digits a previous call kept,
     * and those are accepted only as exactly four digits.
     *
     * Nothing is decrypted and nothing is inferred: no reference, no digits.
     *
     * @param array<array-key, mixed> $raw an order, before or after {@see redactKeepingCardLastFour()}
     */
    public static function cardLastFour(array $raw): ?string
    {
        $data = $raw['data'] ?? null;

        if (is_string($data) && $data !== '') {
            try {
                $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $data = null;
            }
        }

        $data = is_array($data) ? $data : [];
        $cc = isset($data['cc']) && is_array($data['cc']) ? $data['cc'] : [];

        $references = [];

        foreach ([$data['card_num'] ?? null, $cc['card_num'] ?? null] as $reference) {
            if (is_string($reference) && $reference !== '') {
                $references[] = $reference;
            }
        }

        if ($references === []) {
            $kept = $raw[self::CARD_LAST_FOUR] ?? null;

            return is_string($kept) && preg_match('/^\d{4}$/', $kept) === 1 ? $kept : null;
        }

        $lastFour = null;

        foreach ($references as $reference) {
            if (preg_match(self::CARD_REFERENCE, $reference, $match) !== 1) {
                return null;
            }

            if ($lastFour !== null && $lastFour !== $match[1]) {
                return null;
            }

            $lastFour = $match[1];
        }

        return $lastFour;
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
