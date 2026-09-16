<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function str_repeat;

/**
 * Keyterm sanitising, and the deliberate asymmetry between the two limits Deepgram documents.
 *
 * The count is exactly knowable from the list, so it is enforced. The token budget is not — we have no
 * officially compatible tokenizer, and a whitespace word count is neither exact nor reliably
 * conservative — so it may only warn. These tests pin that asymmetry, because the tempting "fix" is to
 * start blocking on the estimate, and that would reject valid configurations Deepgram would have
 * accepted.
 */
final class DeepgramKeytermsTest extends TestCase
{
    public function testTermsAreTrimmed(): void
    {
        $this->assertSame(['wonton', 'lo mein'], DeepgramKeyterms::fromList(['  wonton ', "lo mein\t"])->terms);
    }

    /** A trailing comma or a double separator produces these, and `keyterm=` is noise at best. */
    public function testEmptyTermsAreDropped(): void
    {
        $this->assertSame(['wonton'], DeepgramKeyterms::fromList(['wonton', '', '   '])->terms);
    }

    public function testExactDuplicatesAreDroppedAndOrderIsPreserved(): void
    {
        $terms = DeepgramKeyterms::fromList(['wonton', 'lo mein', 'wonton', 'General Tso'])->terms;

        $this->assertSame(['wonton', 'lo mein', 'General Tso'], $terms);
    }

    /** Case matters to the model, so two casings are two hints, not a duplicate. */
    public function testDeduplicationIsCaseSensitive(): void
    {
        $this->assertSame(['Wonton', 'wonton'], DeepgramKeyterms::fromList(['Wonton', 'wonton'])->terms);
    }

    public function testAnEmptySetIsRecognisable(): void
    {
        $this->assertTrue(DeepgramKeyterms::none()->isEmpty());
        $this->assertTrue(DeepgramKeyterms::fromList(['', ' '])->isEmpty());
        $this->assertFalse(DeepgramKeyterms::fromList(['wonton'])->isEmpty());
    }

    // ------------------------------------------------------------------ the limit we enforce

    public function testExactlyOneHundredTermsIsAccepted(): void
    {
        $terms = DeepgramKeyterms::fromList($this->distinctTerms(DeepgramKeyterms::MAX_TERMS));

        $this->assertSame(DeepgramKeyterms::MAX_TERMS, $terms->count());
        $this->assertNull($terms->countProblem(), 'The documented ceiling is inclusive.');
    }

    public function testOneHundredAndOneTermsIsAProblem(): void
    {
        $terms = DeepgramKeyterms::fromList($this->distinctTerms(DeepgramKeyterms::MAX_TERMS + 1));

        $problem = $terms->countProblem();

        $this->assertNotNull($problem, 'The count is exactly knowable, so it is enforced.');
        $this->assertStringContainsString('101', $problem);
        $this->assertStringContainsString('100', $problem);
    }

    /**
     * De-duplication happens first, so 150 copies of one term is one term.
     *
     * Counting before sanitising would refuse a configuration that sends a single `keyterm=`.
     */
    public function testTheCountIsTakenAfterSanitising(): void
    {
        $terms = DeepgramKeyterms::fromList(array_fill(0, 150, 'wonton'));

        $this->assertSame(1, $terms->count());
        $this->assertNull($terms->countProblem());
    }

    // ------------------------------------------------------------------ the limit Deepgram enforces

    public function testTheTokenEstimateCountsWordsAcrossEveryTerm(): void
    {
        $this->assertSame(4, DeepgramKeyterms::fromList(['General Tso', 'lo mein'])->estimatedTokens());
    }

    public function testAComfortableSetProducesNoWarning(): void
    {
        $this->assertNull(DeepgramKeyterms::fromList(['wonton', 'lo mein'])->budgetWarning());
    }

    /**
     * **The load-bearing test.** An over-budget estimate warns and nothing else.
     *
     * There is no `countProblem()` here, no exception, and nothing that would stop the request being
     * built — Deepgram's own HTTP 400 is the authoritative validation of the token budget, and a local
     * approximation must never pre-empt it.
     */
    public function testAnOverBudgetEstimateWarnsButIsNeverAProblem(): void
    {
        // 60 terms of ten words each: 600 estimated tokens, comfortably over the 500 budget, while the
        // term count stays legal.
        $terms = DeepgramKeyterms::fromList($this->phrases(60, 10));

        $this->assertGreaterThan(DeepgramKeyterms::ADVISORY_TOKEN_BUDGET, $terms->estimatedTokens());
        $this->assertNull($terms->countProblem(), 'The token estimate must never become a blocking problem.');

        $warning = $terms->budgetWarning();
        $this->assertNotNull($warning);
        $this->assertStringContainsString('estimate', $warning);
        $this->assertStringContainsString('not blocked', $warning);
    }

    // ------------------------------------------------------------------ encoding

    /**
     * Pairs, not an associative array.
     *
     * Every array-shaped representation in PHP loses the repetition Deepgram needs: an associative
     * array keeps only the last value, and `http_build_query()` emits `keyterm[0]=`. The final URI is
     * asserted in {@see DeepgramEngineTest}; this asserts the shape that makes it possible.
     */
    public function testQueryPairsRepeatTheKeyOncePerTerm(): void
    {
        $pairs = DeepgramKeyterms::fromList(['wonton', 'lo mein'])->toQueryPairs();

        $this->assertSame([['keyterm', 'wonton'], ['keyterm', 'lo mein']], $pairs);
    }

    public function testAnEmptySetProducesNoPairs(): void
    {
        $this->assertSame([], DeepgramKeyterms::none()->toQueryPairs());
    }

    /**
     * @return list<string>
     */
    private function distinctTerms(int $count): array
    {
        $terms = [];
        for ($i = 0; $i < $count; $i++) {
            $terms[] = 'term' . $i;
        }

        return $terms;
    }

    /**
     * @return list<string> $count distinct phrases of $words words each
     */
    private function phrases(int $count, int $words): array
    {
        $terms = [];
        for ($i = 0; $i < $count; $i++) {
            $terms[] = 'phrase' . $i . str_repeat(' word', $words - 1);
        }

        return $terms;
    }
}
