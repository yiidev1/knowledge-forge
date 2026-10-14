<?php

declare(strict_types=1);

namespace App\Tests\Unit\Practice;

use App\Practice\Web\Assign\FilterReturn;
use PHPUnit\Framework\TestCase;

/**
 * Which filter an assignment redirects back to.
 *
 * The value arrives in a POST body, so it is attacker-controlled: it reaches a `Location` header, and
 * the only reason that is safe is that nothing but the two shapes the listing emits is ever passed on.
 * These cases are what makes that a checked fact.
 */
final class FilterReturnTest extends TestCase
{
    public function testATopicIdIsCarriedBack(): void
    {
        self::assertSame(['topic' => '42'], FilterReturn::arguments('42'));
    }

    public function testUnassignedIsCarriedBack(): void
    {
        self::assertSame(['topic' => 'none'], FilterReturn::arguments('none'));
    }

    /**
     * Anything else is dropped, and dropping it means landing on the unfiltered list.
     *
     * @dataProvider rejected
     */
    public function testAnythingElseIsDropped(string $posted): void
    {
        self::assertSame([], FilterReturn::arguments($posted));
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'absent' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a word' => ['all'];
        yield 'uppercase' => ['NONE'];
        yield 'padded' => [' none'];
        yield 'another parameter' => ['none&page=9'];
        yield 'a path' => ['../../admin'];
        yield 'a url' => ['https://example.com'];
        yield 'crlf' => ["none\r\nX-Evil: 1"];
        yield 'too long' => ['12345678901234567890'];
    }
}
