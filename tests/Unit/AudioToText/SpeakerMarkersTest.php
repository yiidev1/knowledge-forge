<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\SpeakerMarkers;
use PHPUnit\Framework\TestCase;

/**
 * The `>>` markers whisper emits where it hears the speaker change, removed on the way to a reader.
 *
 * Nothing here writes: the markers stay in `transcript` and `speaker_segments`, because they are often
 * the only clue that a diarized turn contains two people and the correction workflow still needs them.
 */
final class SpeakerMarkersTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function samples(): iterable
    {
        yield 'wrapping a whole turn' => ['>> Okay, hold on. >>', 'Okay, hold on.'];
        yield 'mid-sentence' => [
            'fried rice with it, right? >> She wants the shrimp',
            'fried rice with it, right? She wants the shrimp',
        ];
        yield 'leading only' => ['>> Can I get a shrimp', 'Can I get a shrimp'];
        yield 'several in one turn' => [
            '>> Yes. >> Okay, anything else? >> That\'s it.',
            'Yes. Okay, anything else? That\'s it.',
        ];
        yield 'no marker is left alone' => ['Just ordinary words.', 'Just ordinary words.'];
        yield 'no spaces around it' => ['one>>two', 'one two'];
        yield 'collapses the gap it leaves' => ["a  >>   b", 'a b'];
        yield 'nothing but a marker' => ['>>', ''];
        yield 'empty' => ['', ''];
        // A single chevron is punctuation, not a marker, and stays.
        yield 'a lone chevron survives' => ['5 > 3 is true', '5 > 3 is true'];
        yield 'multibyte text is untouched' => ['>> Café ouvert 👍', 'Café ouvert 👍'];
    }

    /**
     * @dataProvider samples
     */
    public function testMarkersAreRemovedAndSpacingNormalised(string $input, string $expected): void
    {
        $this->assertSame($expected, SpeakerMarkers::strip($input));
    }

    /** Stripping is a pure read: the string handed in is unchanged, and running twice changes nothing. */
    public function testStrippingIsIdempotentAndLeavesTheInputAlone(): void
    {
        $original = '>> Okay, hold on. >>';
        $once = SpeakerMarkers::strip($original);

        $this->assertSame($once, SpeakerMarkers::strip($once));
        $this->assertSame('>> Okay, hold on. >>', $original);
    }
}
