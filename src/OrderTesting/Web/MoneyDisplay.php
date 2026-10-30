<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use function intdiv;
use function ltrim;
use function number_format;
use function preg_match;
use function str_pad;
use function strlen;
use function substr;
use function trim;

use const PHP_INT_MAX;
use const STR_PAD_LEFT;

/**
 * An amount of money as a person reads it, for both comparison pages: two decimals, `11.55`.
 *
 * ## Display only
 *
 * Every verdict and every score was already decided on the **stored** strings, by
 * `ComparisonRules::moneyEquals()`, and nothing here is ever fed back into one. So rounding for the
 * reader can neither hide a difference nor invent one, and the five stored decimals stay exactly what
 * the provider sent.
 *
 * ## Decimal strings, never floats
 *
 * Rounding is half away from zero on the decimal digits as written: `2.38500` prints `2.39` because its
 * third decimal is a 5, not because a binary approximation of it happened to land above the midpoint.
 * The arithmetic is on integers scaled by the string's own number of decimals, so it is exact.
 *
 * A value that is not a plain decimal is printed as stored rather than guessed at.
 */
final class MoneyDisplay
{
    /** Digits an amount may carry and still be held exactly in an integer. */
    private const MAX_DIGITS = 15;

    /** `"34.88710"` → `"34.89"`; blank → null; anything that is not a plain amount → as stored. */
    public static function amount(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $decimal = self::parse($value);

        if ($decimal === null) {
            return trim($value);
        }

        [$negative, $units, $scale] = $decimal;
        $cents = self::toCents($units, $scale);
        $sign = $negative && $cents > 0 ? '-' : '';

        return $sign . number_format(intdiv($cents, 100), 0, '.', ',')
            . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Quantity times unit price, exactly, as a decimal string — or null when either is not an amount.
     *
     * Neither provider stores a line total, so the receipt prints this one. It is arithmetic on the two
     * figures printed beside it, done on their decimal digits so `3 × 1.15` is `3.45` and not the
     * `3.4499999999999997` a float would hand to the rounding.
     */
    public static function times(?string $quantity, ?string $unitPrice): ?string
    {
        $q = self::parse($quantity);
        $p = self::parse($unitPrice);

        if ($q === null || $p === null) {
            return null;
        }

        [$qNegative, $qUnits, $qScale] = $q;
        [$pNegative, $pUnits, $pScale] = $p;

        if ($qUnits !== 0 && $pUnits > intdiv(PHP_INT_MAX, $qUnits)) {
            return null;
        }

        $units = $qUnits * $pUnits;
        $scale = $qScale + $pScale;
        $digits = str_pad((string) $units, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $scale);
        $sign = $qNegative !== $pNegative && $units > 0 ? '-' : '';

        return $sign . ($scale === 0 ? $whole : $whole . '.' . substr($digits, -$scale));
    }

    /**
     * A plain decimal as its sign, its digits as one integer, and how many of them are decimals.
     *
     * @return array{bool, int, int}|null
     */
    private static function parse(?string $value): ?array
    {
        if ($value === null || preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', trim($value), $m) !== 1) {
            return null;
        }

        $whole = $m[2];
        $fraction = $m[3] ?? '';

        if ($whole === '' && $fraction === '') {
            return null;
        }

        $digits = ltrim($whole . $fraction, '0');

        if (strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        return [$m[1] === '-', (int) ($digits === '' ? '0' : $digits), strlen($fraction)];
    }

    /** Whole cents, rounded half away from zero. `$units` is a magnitude, so "up" is away from zero. */
    private static function toCents(int $units, int $scale): int
    {
        if ($scale <= 2) {
            return $units * self::powerOfTen(2 - $scale);
        }

        $divisor = self::powerOfTen($scale - 2);
        $cents = intdiv($units, $divisor);

        return ($units % $divisor) * 2 >= $divisor ? $cents + 1 : $cents;
    }

    /** `10 ** $exponent` as an integer — the operator's result is typed int|float. */
    private static function powerOfTen(int $exponent): int
    {
        $power = 1;

        for ($i = 0; $i < $exponent; $i++) {
            $power *= 10;
        }

        return $power;
    }
}
