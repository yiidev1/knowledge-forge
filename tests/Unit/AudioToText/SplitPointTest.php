<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\SplitPoint;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * The offsets offered to an administrator, so they never have to count characters.
 *
 * The load-bearing property is the last test: every offer must be one the domain would accept. A
 * position that is certain to be refused is worse than no position at all — it invites the refusal.
 */
final class SplitPointTest extends TestCase
{
    public function testEveryGapBetweenWordsIsOffered(): void
    {
        $points = SplitPoint::forText('Yes. For pickup');

        $this->assertCount(2, $points);
        $this->assertSame(4, $points[0]->offset);
        $this->assertSame('Yes.', $points[0]->after);
        $this->assertSame(8, $points[1]->offset);
        $this->assertSame('For', $points[1]->after);
    }

    public function testSentenceEndsAreMarked(): void
    {
        $points = SplitPoint::forText('Yes. For pickup or delivery? I can do that');

        $sentences = [];
        foreach ($points as $point) {
            if ($point->endsSentence) {
                $sentences[] = $point->after;
            }
        }

        // Where a merged-speaker mistake almost always falls — one person answers, the next begins.
        $this->assertSame(['Yes.', 'delivery?'], $sentences);
    }

    public function testARunOfWhitespaceIsOneChoiceNotSeveral(): void
    {
        $points = SplitPoint::forText('Yes.    For');

        $this->assertCount(1, $points);
        $this->assertSame(4, $points[0]->offset);
    }

    public function testATurnWithNoGapOffersNothing(): void
    {
        $this->assertSame([], SplitPoint::forText('Yes'));
        $this->assertSame([], SplitPoint::forText(''));
    }

    public function testTrailingAndLeadingWhitespaceOfferNoSplit(): void
    {
        // Nothing on the far side to split off, so neither is offered.
        $this->assertSame([], SplitPoint::forText('Yes.   '));
        $this->assertSame([], SplitPoint::forText('   Yes.'));
    }

    public function testMultibyteTextIsCountedInCharactersNotBytes(): void
    {
        $points = SplitPoint::forText('Café ouvert');

        $this->assertCount(1, $points);
        // 'Café' is four characters and five bytes; splitAt() counts characters.
        $this->assertSame(4, $points[0]->offset);
        $this->assertSame('Café', $points[0]->after);
    }

    /** Every offered position must be one the domain accepts. */
    public function testEveryOfferedPositionProducesAValidSplit(): void
    {
        $texts = [
            'Yes. For pickup or delivery?',
            'Café ouvert aujourd\'hui',
            'one two',
            'Hello...  is anyone there? Yes!',
        ];

        foreach ($texts as $text) {
            $conversation = ReviewedConversationTurns::fromUtterances([
                new SpeakerUtterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, $text, 1.0),
            ]);

            foreach (SplitPoint::forText($text) as $point) {
                $split = $conversation->splitAt(0, $point->offset);

                $this->assertSame(2, $split->count(), $text . ' at ' . $point->offset);
                $this->assertNotSame('', $split->turns[0]->text);
                $this->assertNotSame('', $split->turns[1]->text);
            }

            $this->assertGreaterThan(0, count(SplitPoint::forText($text)));
        }
    }
}
