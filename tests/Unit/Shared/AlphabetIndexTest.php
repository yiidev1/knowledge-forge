<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Web\Support\AlphabetIndex;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * Alphabet bucketing for the store directories: case-insensitive, leading whitespace ignored, and anything
 * that is not A–Z grouped under "#".
 */
final class AlphabetIndexTest extends Unit
{
    public function testBucketsAreCaseInsensitiveAndIgnoreLeadingWhitespace(): void
    {
        assertSame('A', AlphabetIndex::letterFor('Aardvark'));
        assertSame('A', AlphabetIndex::letterFor('aardvark'));
        assertSame('A', AlphabetIndex::letterFor('   Applebee'));
        assertSame('Z', AlphabetIndex::letterFor('zebra'));
    }

    public function testDigitsSymbolsAndEmptyBucketUnderHash(): void
    {
        assertSame('#', AlphabetIndex::letterFor('888 Chinese'));
        assertSame('#', AlphabetIndex::letterFor('0000 Test'));
        assertSame('#', AlphabetIndex::letterFor('!Bang'));
        assertSame('#', AlphabetIndex::letterFor('   '));
        assertSame('#', AlphabetIndex::letterFor(''));
        // A non-Latin first character is not A–Z, so it buckets under "#".
        assertSame('#', AlphabetIndex::letterFor('日本'));
    }

    public function testLettersRunAtoZThenHash(): void
    {
        $letters = AlphabetIndex::letters();

        assertCount(27, $letters);
        assertSame('A', $letters[0]);
        assertSame('Z', $letters[25]);
        assertSame('#', $letters[26]);
    }

    public function testNormalizeRequestedLetter(): void
    {
        assertSame(AlphabetIndex::ALL, AlphabetIndex::normalize(null));
        assertSame(AlphabetIndex::ALL, AlphabetIndex::normalize(''));
        assertSame(AlphabetIndex::ALL, AlphabetIndex::normalize('all'));
        assertSame('A', AlphabetIndex::normalize('a'));
        assertSame('Z', AlphabetIndex::normalize('Z'));
        assertSame('#', AlphabetIndex::normalize('#'));
        // Unrecognised input falls back to "all" rather than an empty filter.
        assertSame(AlphabetIndex::ALL, AlphabetIndex::normalize('AB'));
        assertSame(AlphabetIndex::ALL, AlphabetIndex::normalize('7'));
    }
}
