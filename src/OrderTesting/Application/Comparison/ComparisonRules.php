<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Comparison;

use App\OrderTesting\Domain\Comparison\ComparisonStatus;

use function preg_replace;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

/**
 * What "the same" means, in one place, for every field compared.
 *
 * ## Why these are canonicalisations and not tolerances
 *
 * No rule here forgives a difference in the data. Each one removes a difference in how the *same* value
 * was written down, which the two systems do differently for reasons that have nothing to do with the
 * trainee:
 *
 *  - **Money.** The provider sends `"4.28000"` in one document and `"4.28"` in another. Those are one
 *    amount. Comparison is on the canonical decimal; the page still prints what each document says.
 *  - **Text.** Compared case-insensitively with runs of whitespace collapsed. `"Main St"` and
 *    `"main  st"` are the same street typed by two people, and treating them as different would bury the
 *    real differences under noise.
 *  - **Phone.** Compared on digits only. A phone number is an identifier; `(917) 853-2637` and
 *    `19178532637` are the same number, and the formatting is the UI's, not the caller's.
 *
 * There is deliberately **no** near-match, no edit distance, no percentage and no threshold. Scoring
 * will need rules like that and will be told them; a comparison that guessed them now would bake an
 * unreviewed business decision into the data a score is later computed from.
 *
 * ## This is the seam scoring will use
 *
 * Every equality decision in this feature goes through this class. When scoring arrives with its own
 * notion of "close enough" for, say, a product name, it changes here — once — and both the page and the
 * score follow. Nothing else compares two values.
 */
final readonly class ComparisonRules
{
    /** Trimmed, lowercased, internal whitespace collapsed. */
    public static function textEquals(?string $a, ?string $b): bool
    {
        return self::canonicalText($a) === self::canonicalText($b);
    }

    /** The same amount, however many trailing zeros were written. */
    public static function moneyEquals(?string $a, ?string $b): bool
    {
        return self::canonicalMoney($a) === self::canonicalMoney($b);
    }

    /** Digits only: a phone is an identifier, and its punctuation is presentation. */
    public static function phoneEquals(?string $a, ?string $b): bool
    {
        return self::digits($a) === self::digits($b);
    }

    /**
     * The status of one field, given both sides and the rule that decides equality.
     *
     * Two absent values are a **match**: the documents agree that there is nothing there. Calling that
     * "different" would mark every optional field on every order, and a score computed from it would
     * mostly measure how many fields Order58 leaves blank.
     *
     * @param callable(?string, ?string): bool $equals
     */
    public static function status(?string $original, ?string $demo, callable $equals): ComparisonStatus
    {
        if ($original === null && $demo === null) {
            return ComparisonStatus::Match;
        }

        if ($original === null) {
            return ComparisonStatus::ExtraInDemo;
        }

        if ($demo === null) {
            return ComparisonStatus::MissingInDemo;
        }

        return $equals($original, $demo) ? ComparisonStatus::Match : ComparisonStatus::Different;
    }

    private static function canonicalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return strtolower($collapsed ?? trim($value));
    }

    /**
     * `"4.28000"` and `"4.28"` both become `"4.28"`; `"5.00"` becomes `"5"`.
     *
     * Only trailing zeros **after a decimal point** are dropped — a string with no point is left alone,
     * so `"500"` never becomes `"5"`.
     */
    private static function canonicalMoney(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (!str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    private static function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\D+/', '', $value) ?? $value;
    }
}
