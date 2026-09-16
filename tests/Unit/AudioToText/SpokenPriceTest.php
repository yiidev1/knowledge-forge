<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\SpokenPrice;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * The guard rails on the one price rewrite this project performs.
 *
 * Most of this file is negative cases, and that is the point. Turning `$43 and 45` into `$43.45` is
 * worth almost nothing next to the cost of turning a date, an order number or a legitimate pair of
 * prices into one wrong amount — so the tests proving it stays away vastly outnumber the tests proving
 * it acts.
 *
 * Every positive case is drawn from a real recording whose spoken words were recovered from Deepgram's
 * entity `raw_value`. Nothing here was invented to fit the implementation.
 */
final class SpokenPriceTest extends TestCase
{
    // ------------------------------------------------------------------ it fires

    /**
     * @dataProvider realRecordings
     */
    public function testTheShorthandBecomesAPrice(string $spoken, string $transcript, string $expected): void
    {
        $this->assertSame($expected, SpokenPrice::format($transcript), sprintf('Speaker said "%s".', $spoken));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function realRecordings(): array
    {
        return [
            'reference call' => [
                '43 dollars and forty five',
                'is the 1 who no MSG. $43 and 45 ready in 25 minutes.',
                'is the 1 who no MSG. $43.45 ready in 25 minutes.',
            ],
            'second real call' => [
                '15 dollars and twenty one',
                'No pork. Yes. Okay. $15 and 21. Okay? Ready in about 15 minutes.',
                'No pork. Yes. Okay. $15.21. Okay? Ready in about 15 minutes.',
            ],
            'round total' => ['8 dollars and fifty', '$8 and 50', '$8.50'],
            'three figures' => ['105 dollars and twenty five', '$105 and 25', '$105.25'],
        ];
    }

    /**
     * @dataProvider punctuationCases
     */
    public function testSurroundingPunctuationSurvives(string $in, string $expected): void
    {
        $this->assertSame($expected, SpokenPrice::format($in));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function punctuationCases(): array
    {
        return [
            'period' => ['$43 and 45.', '$43.45.'],
            'comma' => ['$43 and 45,', '$43.45,'],
            'question' => ['$43 and 45?', '$43.45?'],
            'parens' => ['($43 and 45)', '($43.45)'],
            'quotes' => ['"$43 and 45"', '"$43.45"'],
        ];
    }

    // ------------------------------------------------------------ the ambiguous form is left alone

    /**
     * A single-digit remainder is genuinely ambiguous and has **no corpus support**.
     *
     * Across 48,514 characters of real transcripts the two-digit form appears 7 times and this form
     * zero times. "$43 and 5" could be $43.05, $43.50, or "$43 and item 5" — so the honest answer is
     * to leave it exactly as the provider said it.
     *
     * @dataProvider ambiguousForms
     */
    public function testAmbiguousSingleDigitFormsAreNotTouched(string $text): void
    {
        $this->assertSame($text, SpokenPrice::format($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ambiguousForms(): array
    {
        return [
            'single digit' => ['$43 and 5'],
            'zero' => ['$43 and 0'],
            'single digit, small total' => ['$12 and 5'],
            'three digits' => ['$43 and 145'],
        ];
    }

    // ------------------------------------------------------- the cents must END where they end

    /**
     * Two digits followed by anything word-like were never cents.
     *
     * An earlier build guarded only a following *digit*, so `$43 and 45kg` became `$43.45kg` — a
     * measurement silently turned into a price. Letters, underscores and hyphens are all disqualifying:
     * a measurement, an ordinal, an identifier and a range all look like this and none of them is money.
     *
     * @dataProvider trailingBoundaryCases
     */
    public function testTwoDigitsFollowedByAWordCharacterAreNotCents(string $text): void
    {
        $this->assertSame($text, SpokenPrice::format($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingBoundaryCases(): array
    {
        $cases = [
            // measurements
            '$43 and 45kg', '$43 and 45lbs', '$43 and 45oz', '$43 and 45g', '$43 and 45ml',
            // ordinals
            '$43 and 45th', '$43 and 1st',
            // identifiers and ranges
            '$43 and 45A', '$43 and 45_1', '$43 and 45abc', '$43 and 45x', '$43 and 45-50',
            // longer numbers
            '$43 and 45.50', '$43 and 45,000',
        ];

        $named = [];
        foreach ($cases as $case) {
            $named[$case] = [$case];
        }

        return $named;
    }

    // ------------------------------------------------------------------ it must NOT fire

    /**
     * The client's full negative list, verbatim.
     *
     * Dates, times, identifiers, quantities, percentages and bare numbers must all survive untouched.
     * `$43 and 45%` is here because an earlier build turned it into `$43.45%` — the word `percent` was
     * guarded and the symbol was not.
     *
     * @dataProvider mustNotChange
     */
    public function testLeavesEverythingElseAlone(string $text): void
    {
        $this->assertSame($text, SpokenPrice::format($text));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mustNotChange(): array
    {
        $cases = [
            // two prices, never merged
            '$43 and $45', '$43 and 45 dollars',
            'The first one is $43 and the second is $45.', '$43.45 and $45.20.',
            // units
            '$43 and 45 minutes', '$43 and 45 orders', '$43 and 45 items', '$43 and 45 people',
            '$43 and 45 each', '$43 and 45 percent', '$43 and 45%', '$43 and 45 pounds',
            '$43 and 45 ounces', '$43 and 45 cents',
            // no currency signal at all
            '43 and 45', '43 and 45 minutes', '43 and 45 orders', 'order 43 and 45',
            'items 43 and 45', '43 dollars and 45', '1 and 2', '10 and 20', '25 and 45',
            // dates and times
            'September 15', 'September 15 and 16', '9/15/2026', '09/15/26',
            '4:45', '4:45 PM', '2026-09-15',
            // identifiers
            'phone 555 43 45', 'extension 45', 'ZIP 39435', 'SKU 4345', 'Order #4345',
            'Ticket 43-45', 'Store 43', 'Table 45', 'Room 45', 'Highway 45',
            // already-correct money
            '$0.99', '$8.50', '$15.21', '$27.75', '$43.45', '$105.25',
            '$1,234.56', '$12,000', '$12,000.50',
        ];

        $named = [];
        foreach ($cases as $case) {
            $named[$case] = [$case];
        }

        return $named;
    }

    // ------------------------------------------------------- currency identity is never invented

    /**
     * **No `$` is ever introduced**, and no currency is ever converted into another.
     *
     * The dollar sign must already be in the text — Deepgram's smart_format puts it there only after
     * hearing the word "dollars". Context alone ("your total is 43 and 45") is never enough.
     *
     * @dataProvider otherCurrencies
     */
    public function testOtherCurrenciesAreUntouchedAndNoDollarIsInvented(string $text): void
    {
        $result = SpokenPrice::format($text);

        $this->assertSame($text, $result);

        if (!str_contains($text, '$')) {
            $this->assertStringNotContainsString('$', $result, 'A currency symbol must never be invented.');
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherCurrencies(): array
    {
        return [
            'yen/yuan' => ['¥43 and 45'],
            'fullwidth yuan' => ['￥43 and 45'],
            'euro' => ['€43 and 45'],
            'pound' => ['£43 and 45'],
            'yuan character' => ['43元 and 45'],
            'renminbi' => ['43人民币 and 45'],
            'RMB' => ['RMB 43 and 45'],
            'CNY' => ['CNY 43 and 45'],
            'USD word' => ['USD 43 and 45'],
            'conversational total' => ['your total is 43 and 45'],
        ];
    }

    // ------------------------------------------------------------------ properties

    /**
     * @dataProvider idempotencyCases
     */
    public function testIsIdempotent(string $text): void
    {
        $once = SpokenPrice::format($text);
        $this->assertSame($once, SpokenPrice::format($once));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function idempotencyCases(): array
    {
        return [
            'shorthand' => ['$43 and 45 ready in 25 minutes'],
            'already correct' => ['$0.99 and $1,234.56'],
            'mixed' => ['$43 and 45 then $27.75 then $12,000.50'],
        ];
    }

    public function testHandlesSeveralInOneTranscript(): void
    {
        $this->assertSame(
            'first $12.99 then $43.45 done',
            SpokenPrice::format('first $12 and 99 then $43 and 45 done'),
        );
    }

    public function testSurroundingTextAndLineStructureSurvive(): void
    {
        $in = "Just the egg foo young and the shrimp fried rice is the 1 who no MSG.\n"
            . "\$43 and 45 ready in 25 minutes. No MSG for anything.";
        $out = SpokenPrice::format($in);

        $this->assertStringContainsString('egg foo young', $out);
        $this->assertStringContainsString("no MSG.\n", $out, 'Line structure must survive.');
        $this->assertStringContainsString('ready in 25 minutes', $out);
        $this->assertStringContainsString('$43.45', $out);
    }

    // ------------------------------------------------- human corrections outrank automatic display

    /**
     * **The authority rule.** A saved correction is shown exactly as the human wrote it.
     *
     * Including — especially — when they deliberately left the shorthand alone. Silently "fixing" that
     * would overrule a decision somebody made on purpose.
     */
    public function testAReviewedTranscriptIsNeverReformatted(): void
    {
        $this->assertSame(
            '$43 and 45',
            SpokenPrice::formatUnlessReviewed('$43 and 45', true),
            'An explicit human correction outranks automatic formatting.',
        );
    }

    public function testUnreviewedMachineTextIsFormatted(): void
    {
        $this->assertSame('$43.45', SpokenPrice::formatUnlessReviewed('$43 and 45', false));
    }

    /** The flag decides, and nothing else: identical input, opposite outcomes. */
    public function testTheReviewedFlagIsTheOnlyDifference(): void
    {
        $text = 'Your total is $15 and 21 today.';

        $this->assertNotSame(
            SpokenPrice::formatUnlessReviewed($text, true),
            SpokenPrice::formatUnlessReviewed($text, false),
        );
    }
}
