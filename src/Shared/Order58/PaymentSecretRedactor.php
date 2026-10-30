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
use function trim;

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
     * The card's expiry, kept beside {@see CARD_LAST_FOUR} and under the same rules.
     *
     * Cardholder data rather than authentication data: PCI DSS permits retaining it, unlike the CVV
     * beside it in the provider's record, which is stripped and never kept.
     */
    public const CARD_EXPIRY = 'card_expiry';

    /** The billing postcode. Present on a minority of records; absent is the normal case. */
    public const BILLING_ZIP = 'billing_zip_kept';

    /** `MM/YYYY`, the only shape the provider sends — measured across every card order. */
    private const EXPIRY_FORMAT = '/^(0[1-9]|1[0-2])\/(20\d{2})$/';

    /** Five digits, or five plus four. Anything else is not a postcode and is refused. */
    private const ZIP_FORMAT = '/^\d{5}(-\d{4})?$/';

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

        // Whatever arrived under these names is dropped and replaced by what can be proved — see
        // provenPaymentKeys(), which is also what the mapper and the demo document use, so all three
        // callers keep exactly the same fields under exactly the same rules.
        unset($clean[self::CARD_LAST_FOUR], $clean[self::CARD_EXPIRY], $clean[self::BILLING_ZIP]);

        return $clean + self::provenPaymentKeys($raw);
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
     * The card's expiry date, or null when it cannot be proved.
     *
     * Read from `data.cc.exp_date`, exactly as {@see cardLastFour()} reads the reference beside it. A
     * record already redacted is asked for the value a previous call kept, and that is accepted only
     * in the one shape the provider uses — so a malformed or hand-edited row yields nothing rather
     * than a date the page would print as fact.
     *
     * Measured 2026-10-30 on every card order of the live response: 30 of 30 are `MM/YYYY`.
     *
     * @param array<array-key, mixed> $raw an order, before or after redaction
     */
    public static function cardExpiry(array $raw): ?string
    {
        $value = self::fromCc($raw, 'exp_date');

        if ($value === null) {
            $kept = $raw[self::CARD_EXPIRY] ?? null;

            return is_string($kept) && preg_match(self::EXPIRY_FORMAT, $kept) === 1 ? $kept : null;
        }

        return preg_match(self::EXPIRY_FORMAT, $value) === 1 ? $value : null;
    }

    /**
     * The billing postcode, or null.
     *
     * Empty far more often than not — 24 of 30 card orders carry an empty string — so null is the
     * ordinary answer here, and the pages are built to omit the row rather than print a blank one.
     *
     * @param array<array-key, mixed> $raw
     */
    public static function billingZip(array $raw): ?string
    {
        $value = self::fromCc($raw, 'billing_zip');

        if ($value === null) {
            $kept = $raw[self::BILLING_ZIP] ?? null;

            return is_string($kept) && preg_match(self::ZIP_FORMAT, $kept) === 1 ? $kept : null;
        }

        return preg_match(self::ZIP_FORMAT, $value) === 1 ? $value : null;
    }

    /**
     * The keys this class may re-attach after redaction.
     *
     * Exists so the mapper and the demo document can hold these out of their fingerprints without
     * listing them again — a second list would be the thing that drifted.
     *
     * @return list<string>
     */
    public static function keptPaymentKeys(): array
    {
        return [self::CARD_LAST_FOUR, self::CARD_EXPIRY, self::BILLING_ZIP];
    }

    /**
     * What may be kept about the card on a record **the provider sent**, ready to merge into a payload.
     *
     * ## Why the kept keys are stripped from the input first
     *
     * All three are this class's own output — the provider sends `cc.card_num`, `cc.exp_date` and
     * `cc.billing_zip`, never `card_last4`, `card_expiry` or `billing_zip_kept`. So a provider record
     * that arrives carrying one is carrying something another hand put there, and the readers below
     * would accept it through the fallback they keep for already-redacted records. The keys would then
     * be self-asserting: set `card_expiry` on an incoming document and it would be stored as fact.
     *
     * They are therefore removed before anything is read, which leaves `data.cc` as the only source.
     *
     * Use this for a document from the provider or from a demo file. For a record this application has
     * already stored and redacted, where the kept keys ARE the only remaining source, use
     * {@see readPaymentKeys()} instead.
     *
     * @param array<array-key, mixed> $raw a record as the provider sent it
     *
     * @return array<string, string> only the keys that could be proved; empty for a cash order
     */
    public static function provenPaymentKeys(array $raw): array
    {
        unset($raw[self::CARD_LAST_FOUR], $raw[self::CARD_EXPIRY], $raw[self::BILLING_ZIP]);

        return self::readPaymentKeys($raw);
    }

    /**
     * The same three fields, read from wherever the record states them.
     *
     * For a record this application stored: its secrets are long gone, so the kept keys are the only
     * source left and each reader falls back to them — still format-checked, so a hand-edited row
     * yields nothing rather than text the pages would print as fact.
     *
     * Trusts the kept keys by design. Do not use it on anything the provider just sent;
     * {@see provenPaymentKeys()} is the one for that.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    public static function readPaymentKeys(array $raw): array
    {
        $kept = [];

        foreach (
            [
                self::CARD_LAST_FOUR => self::cardLastFour($raw),
                self::CARD_EXPIRY => self::cardExpiry($raw),
                self::BILLING_ZIP => self::billingZip($raw),
            ] as $key => $value
        ) {
            if ($value !== null) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * One non-empty string from `data.cc`, with `data` decoded if it arrived as JSON.
     *
     * Extracted so the two readers above cannot drift from each other, and so the `data`-as-string
     * case is handled once. Deliberately does NOT look at top-level siblings the way
     * {@see cardLastFour()} does for `card_num`: expiry and postcode appear only inside `cc`, and
     * widening the search would be inventing a source.
     *
     * @param array<array-key, mixed> $raw
     */
    private static function fromCc(array $raw, string $key): ?string
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
        $value = $cc[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
