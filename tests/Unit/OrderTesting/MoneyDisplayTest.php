<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Web\MoneyDisplay;
use PHPUnit\Framework\TestCase;

/**
 * How both comparison pages print an amount: two decimals, rounded half away from zero on the decimal
 * digits as stored — never through a float.
 */
final class MoneyDisplayTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function amounts(): iterable
    {
        yield 'five stored decimals' => ['11.55000', '11.55'];
        yield 'a grand total rounds up' => ['34.88710', '34.89'];
        yield 'a tax rounds up' => ['2.38620', '2.39'];
        yield 'a fee rounds down' => ['0.85090', '0.85'];
        yield 'an exact midpoint rounds up' => ['2.38500', '2.39'];
        yield 'just under the midpoint rounds down' => ['2.38499', '2.38'];
        yield 'a binary-awkward midpoint' => ['0.285', '0.29'];
        yield 'rounding carries into the whole' => ['999.99500', '1,000.00'];
        yield 'a stated zero is an answer' => ['0.00000', '0.00'];
        yield 'a whole number' => ['4', '4.00'];
        yield 'a negative rounds away from zero' => ['-2.385', '-2.39'];
        yield 'a negative that rounds to nothing has no sign' => ['-0.004', '0.00'];
        yield 'surrounding space' => ['  4.28  ', '4.28'];
        yield 'thousands are grouped' => ['1234567.895', '1,234,567.90'];
        yield 'blank is no amount' => ['  ', null];
        yield 'null is no amount' => [null, null];
        yield 'not a plain amount is printed as stored' => ['n/a', 'n/a'];
    }

    /** @dataProvider amounts */
    public function testAnAmountPrintsWithTwoDecimals(?string $stored, ?string $shown): void
    {
        self::assertSame($shown, MoneyDisplay::amount($stored));
    }

    /** `3 × 1.15` is `3.45` — a float would make it `3.4499999999999997`. */
    public function testALineTotalIsExact(): void
    {
        self::assertSame('3.45', MoneyDisplay::times('3', '1.15'));
        self::assertSame('23.10000', MoneyDisplay::times('2', '11.55000'));
        self::assertSame('12.05000000', MoneyDisplay::times('1.000', '12.05000'));
        self::assertSame('23.10', MoneyDisplay::amount(MoneyDisplay::times('2', '11.55000')));
    }

    public function testALineTotalNeedsTwoAmounts(): void
    {
        self::assertNull(MoneyDisplay::times(null, '1.00'));
        self::assertNull(MoneyDisplay::times('1', ''));
        self::assertNull(MoneyDisplay::times('two', '1.00'));
    }
}
