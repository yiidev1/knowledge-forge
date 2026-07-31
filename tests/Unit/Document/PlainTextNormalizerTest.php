<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Text\PlainTextNormalizer;
use Codeception\Test\Unit;

use function hash;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The normalizer is the guarantee behind "unchanged content is never re-indexed": the same knowledge,
 * however it was typed or line-ended, must produce byte-identical output so its checksum is stable.
 */
final class PlainTextNormalizerTest extends Unit
{
    public function testStripsBomUnifiesLineEndingsAndAddsOneTrailingNewline(): void
    {
        assertSame("a\nb\nc\n", PlainTextNormalizer::normalize("\xEF\xBB\xBFa\r\nb\rc"));
    }

    public function testTrimsAndCollapsesRunsOfBlankLines(): void
    {
        assertSame("foo\n\nbar\n", PlainTextNormalizer::normalize("\n\n\nfoo\n\n\n\nbar\n\n\n"));
    }

    public function testStripsTrailingWhitespacePerLine(): void
    {
        assertSame("foo\nbar\n", PlainTextNormalizer::normalize("foo   \nbar\t\n"));
    }

    public function testWhitespaceOnlyBecomesEmptyString(): void
    {
        assertSame('', PlainTextNormalizer::normalize("   \n\n  \t "));
    }

    /**
     * Two inputs that differ only in line endings and trailing whitespace must hash identically — this is
     * exactly the pair that an edit-with-no-real-change produces.
     */
    public function testCosmeticDifferencesProduceTheSameChecksum(): void
    {
        $crlf = "Title\r\n\r\nBody line one   \r\nBody line two\r\n";
        $lf = "Title\n\nBody line one\nBody line two";

        assertSame(
            hash('sha256', PlainTextNormalizer::normalize($crlf)),
            hash('sha256', PlainTextNormalizer::normalize($lf)),
        );
    }

    public function testNormalizationIsIdempotent(): void
    {
        $once = PlainTextNormalizer::normalize("messy\r\n\r\n\r\ntext   \n");

        assertSame($once, PlainTextNormalizer::normalize($once));
    }

    public function testValidatesUtf8(): void
    {
        assertTrue(PlainTextNormalizer::isValidUtf8("héllo — çava\n"));
        assertFalse(PlainTextNormalizer::isValidUtf8("\xFF\xFE bad bytes"));
    }
}
