<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Application\RuleHasher;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The exact-duplicate identity rule: canonical_hash = SHA-256(normalized title + "\0" + normalized description).
 * Two rules are the same canonical rule ONLY when both their normalized title and description match; rules that
 * merely share a title stay separate. Whitespace-only differences normalize away; material differences do not.
 */
final class RuleHasherTest extends Unit
{
    private RuleHasher $hasher;

    protected function _before(): void
    {
        $this->hasher = new RuleHasher();
    }

    public function testIdenticalContentProducesTheSameCanonicalHash(): void
    {
        $a = $this->hasher->identify('Moon Temple', 'Call back when a call drops.');
        $b = $this->hasher->identify('Moon Temple', 'Call back when a call drops.');

        assertSame($a->canonicalHash, $b->canonicalHash);
    }

    public function testSameTitleWithDifferentDescriptionsStaySeparate(): void
    {
        $a = $this->hasher->identify('Moon Temple', 'Rule one body.');
        $b = $this->hasher->identify('Moon Temple', 'A completely different body.');

        assertNotSame($a->canonicalHash, $b->canonicalHash, 'same title + different description must not merge');
    }

    public function testWhitespaceOnlyDifferencesDeduplicate(): void
    {
        $a = $this->hasher->identify('Advance Order', "Line one\nLine two");
        // CRLF line endings, trailing spaces and a trailing newline all normalize away (the line structure
        // is unchanged, so this is a genuine whitespace-only difference).
        $b = $this->hasher->identify('Advance Order  ', "Line one  \r\nLine two  \r\n");

        assertSame($a->canonicalHash, $b->canonicalHash);
        assertSame($a->descriptionHash, $b->descriptionHash);
    }

    public function testMaterialContentDifferencesDoNotDeduplicate(): void
    {
        $a = $this->hasher->identify('Advance Order', 'Accept advance orders before opening.');
        $b = $this->hasher->identify('Advance Order', 'Do not accept advance orders.');

        assertNotSame($a->canonicalHash, $b->canonicalHash);
    }

    public function testEmptyDescriptionStillContributesTitleAndDoesNotGroupAsPossibleDuplicate(): void
    {
        $a = $this->hasher->identify('Golden Palace', '');
        $b = $this->hasher->identify('Silver Palace', '');

        // The title alone determines identity when the description is empty.
        assertNotSame($a->canonicalHash, $b->canonicalHash);
        // Empty descriptions must NOT share a description hash (would falsely flag them as possible duplicates);
        // each falls back to its own canonical hash.
        assertSame($a->canonicalHash, $a->descriptionHash);
        assertNotSame($a->descriptionHash, $b->descriptionHash);
        // Canonical content falls back to the normalized title.
        assertSame("Golden Palace\n", $a->content);
    }

    public function testSameDescriptionDifferentTitleSharesTheDescriptionHashForReview(): void
    {
        $a = $this->hasher->identify('Store A', 'General callback procedure.');
        $b = $this->hasher->identify('Store B', 'General callback procedure.');

        // Different canonical rules (titles differ) …
        assertNotSame($a->canonicalHash, $b->canonicalHash);
        // … but a shared description hash, so the report can surface them as *possible* duplicates for review.
        assertSame($a->descriptionHash, $b->descriptionHash);
    }
}
