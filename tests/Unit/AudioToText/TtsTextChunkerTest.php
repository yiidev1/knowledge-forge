<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Tts\TtsTextChunker;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_sum;
use function implode;
use function mb_check_encoding;
use function mb_strlen;
use function rtrim;
use function str_repeat;

/**
 * The chunker's one promise, tested from every angle that could break it.
 *
 * `implode('', split($t)) === $t` is not a nice-to-have. Deepgram is sent one chunk per request, and
 * whatever comes back is what an agent hears — so a chunker that trimmed a space, normalised a quote or
 * dropped an overflow would make the audio disagree with the transcript it claims to be a reading of,
 * in exactly the places nobody checks. A price, a product name, an address.
 *
 * Splitting exists for one reason only: Aura answers HTTP 413 above 2,000 characters.
 */
final class TtsTextChunkerTest extends TestCase
{
    private TtsTextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new TtsTextChunker();
    }

    // ------------------------------------------------------------------ the contract

    /**
     * @dataProvider texts
     */
    public function testConcatenatingTheChunksReproducesTheInputExactly(string $text, int $max): void
    {
        $chunks = $this->chunker->split($text, $max);

        $this->assertSame($text, implode('', $chunks), 'Chunking must not alter a single byte.');
    }

    /**
     * @dataProvider texts
     */
    public function testNoChunkExceedsTheLimit(string $text, int $max): void
    {
        foreach ($this->chunker->split($text, $max) as $chunk) {
            $this->assertLessThanOrEqual(
                $max,
                mb_strlen($chunk, 'UTF-8'),
                'A chunk over the limit is a 413 that has already been paid for.',
            );
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function texts(): array
    {
        return [
            'empty' => ['', 100],
            'shorter than the limit' => ['Just the egg foo young, no MSG.', 100],
            'exactly the limit' => [str_repeat('a', 100), 100],
            'one over the limit' => [str_repeat('a', 101), 100],
            'many sentences' => [str_repeat('Hello there. ', 400), 120],
            'no sentence ending anywhere' => [str_repeat('word ', 900), 100],
            'one unbroken token' => [str_repeat('x', 5000), 100],
            'accented characters' => [str_repeat('café naïve ', 300), 100],
            'cjk without spaces' => [str_repeat('日本語のテキストです。', 200), 100],
            'emoji' => [str_repeat('nice 👍 thanks 🙏 ', 200), 90],
            'newlines and blank lines' => ["Line one.\n\nLine two.\n" . str_repeat('more here ', 200), 150],
            'runs of whitespace' => ["one  two\tthree \n four. " . str_repeat("five  six ", 200), 90],
            'a real order line' => ['$43 and 45 ready in 25 minutes. ' . str_repeat('Egg foo young, no MSG. ', 200), 130],
            'decimals and abbreviations' => ['Mr. Smith paid $12.50 at 4:45 PM. ' . str_repeat('Then he left. ', 200), 110],
        ];
    }

    // ------------------------------------------------------------------ nothing is normalised

    /**
     * The specific normalisations a "tidy" chunker would perform, each asserted absent.
     *
     * Every one of these is something a reasonable person might add, and every one would change what an
     * agent hears relative to what an administrator corrected.
     */
    public function testDoubleSpacesAndTabsSurviveAcrossAChunkBoundary(): void
    {
        $text = str_repeat('alpha  beta', 40) . "\t\tgamma";

        $this->assertSame($text, implode('', $this->chunker->split($text, 50)));
    }

    public function testLeadingAndTrailingWhitespaceIsNeverTrimmed(): void
    {
        $text = '   ' . str_repeat('word ', 100) . '   ';

        $this->assertSame($text, implode('', $this->chunker->split($text, 60)));
    }

    public function testShortTextIsReturnedAsASingleUntouchedChunk(): void
    {
        $text = '  $43 and 45.  ';

        $this->assertSame([$text], $this->chunker->split($text, 2000));
    }

    public function testEmptyTextProducesNoRequests(): void
    {
        $this->assertSame([], $this->chunker->split('', 1900));
    }

    // ------------------------------------------------------------------ where it cuts

    public function testItPrefersToEndAChunkOnASentence(): void
    {
        $chunks = $this->chunker->split('One two three. Four five six. Seven eight nine.', 20);

        $this->assertSame('One two three. ', $chunks[0]);
        $this->assertSame('One two three. Four five six. Seven eight nine.', implode('', $chunks));
    }

    /**
     * A decimal point is not a sentence ending.
     *
     * `$12.50` splitting after the `.` would send "twelve dollars" and "fifty" as two unrelated
     * requests, which is an audible and wrong reading of a price.
     */
    public function testADecimalPointIsNotASentenceBoundary(): void
    {
        $chunks = $this->chunker->split('Your total is $12.50 for the order today please', 24);

        // The price has to survive whole inside one chunk. A chunk *ending* at the decimal point is the
        // failure: it would send "Your total is $12." and "50 for the order" as two unrelated readings.
        foreach ($chunks as $chunk) {
            $this->assertStringEndsNotWith('$12.', rtrim($chunk), 'A price was split at its decimal point.');
        }

        $this->assertStringContainsString('$12.50', $chunks[0], 'The price should be read as one amount.');
    }

    /**
     * An abbreviation **can** end a chunk, and that is a deliberate, documented limitation.
     *
     * The alternative is a list of known abbreviations: per-language, never complete, and wrong in a
     * fresh way every time somebody extends it. The cost is also asymmetric — a boundary after "Mr." is
     * a slightly long pause with every word still intact, while a boundary inside "$12.50" changes what
     * the listener is told. The rule guards the second and accepts the first.
     *
     * Pinned as a test so the behaviour is a decision on record rather than something discovered later
     * and "fixed" without knowing why it was left.
     */
    public function testAnAbbreviationMayEndAChunkAndNoWordsAreLost(): void
    {
        $text = 'Delivery to Mr. Smith on Elm St. today please, thanks';
        $chunks = $this->chunker->split($text, 22);

        $this->assertSame('Delivery to Mr. ', $chunks[0], 'The accepted limitation, stated explicitly.');
        $this->assertSame($text, implode('', $chunks), 'However it splits, every word still arrives.');
    }

    public function testItFallsBackToAWordBoundary(): void
    {
        $chunks = $this->chunker->split('alpha bravo charlie delta echo foxtrot', 12);

        $this->assertSame('alpha bravo ', $chunks[0]);
        $this->assertSame('alpha bravo charlie delta echo foxtrot', implode('', $chunks));
    }

    /**
     * A single token longer than the whole limit is split rather than refused.
     *
     * An odd pronunciation is a worse outcome than no audio, and both are worse than dropping the
     * overflow — which is the one option that would make the recording lie about the transcript.
     */
    public function testAnUnbreakableTokenIsSplitRatherThanDropped(): void
    {
        $text = str_repeat('z', 250);
        $chunks = $this->chunker->split($text, 100);

        $this->assertCount(3, $chunks);
        $this->assertSame($text, implode('', $chunks));
    }

    /** Multibyte characters are never cut in half — that would produce invalid UTF-8, not a split word. */
    public function testMultibyteCharactersAreNeverSplitMidSequence(): void
    {
        $text = str_repeat('日', 300);

        foreach ($this->chunker->split($text, 100) as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'A chunk contained a broken UTF-8 sequence.');
        }
    }

    // ------------------------------------------------------------------ the billed quantity

    /**
     * The sum of the chunks is the whole, which is what makes it safe to quote a cost before splitting.
     */
    public function testTheCharacterCountMatchesTheSumOfTheChunks(): void
    {
        $text = str_repeat('Hello there, how can I help you today? ', 200);

        $perChunk = array_map(
            static fn(string $c): int => mb_strlen($c, 'UTF-8'),
            $this->chunker->split($text, 300),
        );

        $this->assertSame($this->chunker->characterCount($text), (int) array_sum($perChunk));
    }
}
