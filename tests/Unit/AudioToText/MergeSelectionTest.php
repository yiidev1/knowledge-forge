<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function mb_strpos;

/**
 * Moving a highlighted range into the turn beside it.
 *
 * The range decides, never the text: selecting the second "yes" in "yes no yes" has to move that one,
 * and a substring search would find the first. Offsets count codepoints, matching `mb_substr`, because
 * the browser counts UTF-16 units and the two disagree from the first emoji onward.
 */
final class MergeSelectionTest extends TestCase
{
    public function testMovingASelectionIntoThePreviousTurnLeavesTheRestBehind(): void
    {
        $turns = $this->conversation()->mergeSelection(
            1,
            MergeDirection::Previous,
            ...$this->range(1, 'fried rice,'),
        );

        $this->assertSame(3, $turns->count(), 'The source turn still has words, so it stays.');
        $this->assertSame('Hello there. fried rice,', $turns->turns[0]->text);
        $this->assertSame('So lo mein with shrimp', $turns->turns[1]->text);
        $this->assertSame('Anything else?', $turns->turns[2]->text, 'The far side is untouched.');
    }

    public function testMovingASelectionIntoTheNextTurnPutsItInFront(): void
    {
        $turns = $this->conversation()->mergeSelection(
            1,
            MergeDirection::Next,
            ...$this->range(1, 'fried rice,'),
        );

        $this->assertSame(3, $turns->count());
        $this->assertSame('Hello there.', $turns->turns[0]->text);
        $this->assertSame('So lo mein with shrimp', $turns->turns[1]->text);
        $this->assertSame('fried rice, Anything else?', $turns->turns[2]->text);
    }

    /** Nothing left behind is the whole-turn merge, and then the source does disappear. */
    public function testSelectingEveryWordFallsBackToAWholeTurnMerge(): void
    {
        $turns = $this->conversation()->mergeSelection(
            1,
            MergeDirection::Previous,
            ...$this->range(1, 'So lo mein with shrimp fried rice,'),
        );

        $this->assertSame(2, $turns->count(), 'The source turn is gone.');
        $this->assertSame('Hello there. So lo mein with shrimp fried rice,', $turns->turns[0]->text);
        // A whole-turn merge spans both, exactly as it always did.
        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(4000, $turns->turns[0]->endMs);
    }

    /** The offsets pick the occurrence; a substring search would take the first. */
    public function testTheSecondOfThreeIdenticalWordsIsTheOneThatMoves(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Start.'),
            $this->utterance(1000, 2000, 'SPEAKER_01', SpeakerRole::CUSTOMER, 'yes no yes no yes'),
        ]);

        // The middle "yes" — offsets 7..10 of "yes no yes no yes".
        $moved = $turns->mergeSelection(1, MergeDirection::Previous, 7, 10, 'yes');

        $this->assertSame('Start. yes', $moved->turns[0]->text);
        $this->assertSame('yes no no yes', $moved->turns[1]->text, 'The middle one left, not an end one.');
    }

    public function testASelectionFromTheMiddleTakesOnlyItself(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Start.'),
            $this->utterance(1000, 2000, 'SPEAKER_01', SpeakerRole::CUSTOMER, 'one two three four five'),
        ]);

        $moved = $turns->mergeSelection(1, MergeDirection::Previous, 8, 18, 'three four');

        $this->assertSame('Start. three four', $moved->turns[0]->text);
        $this->assertSame('one two five', $moved->turns[1]->text, 'One space at the seam, never two.');
    }

    /** Role and voice are not consulted: this is the manual correction path. */
    public function testASelectionMovesAcrossDifferentRolesAndVoices(): void
    {
        $turns = $this->conversation()->mergeSelection(
            1,
            MergeDirection::Previous,
            ...$this->range(1, 'fried rice,'),
        );

        $this->assertSame(SpeakerRole::AGENT, $turns->turns[0]->role);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[1]->role);
        $this->assertSame('SPEAKER_00', $turns->turns[0]->speaker);
        $this->assertSame('SPEAKER_01', $turns->turns[1]->speaker);
    }

    /**
     * No invented precision.
     *
     * Token timings are not persisted, so the moment the moved words were spoken is unknown. Both
     * turns keep the spans they had and are marked approximate — this codebase's existing way of
     * saying the boundaries no longer bound the text.
     */
    public function testNeitherTurnGainsATimestampNobodyMeasured(): void
    {
        $turns = $this->conversation()->mergeSelection(
            1,
            MergeDirection::Previous,
            ...$this->range(1, 'fried rice,'),
        );

        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(1000, $turns->turns[0]->endMs);
        $this->assertSame(2000, $turns->turns[1]->startMs);
        $this->assertSame(4000, $turns->turns[1]->endMs);

        foreach ([$turns->turns[0], $turns->turns[1]] as $touched) {
            $this->assertTrue($touched->approx, 'The span no longer bounds the text.');
            $this->assertTrue($touched->edited);
        }
    }

    /** Offsets are codepoints, so an emoji earlier in the turn must not shift the cut. */
    public function testOffsetsCountCodepointsNotUtf16Units(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Start.'),
            $this->utterance(1000, 2000, 'SPEAKER_01', SpeakerRole::CUSTOMER, 'Great 👍 thanks a lot'),
        ]);

        // "thanks" begins at codepoint 8. In UTF-16 it would be 9, and the cut would eat a space.
        $moved = $turns->mergeSelection(1, MergeDirection::Previous, 8, 14, 'thanks');

        $this->assertSame('Start. thanks', $moved->turns[0]->text);
        $this->assertSame('Great 👍 a lot', $moved->turns[1]->text);
    }

    /** Whisper's markers are gone from the display, so the range is measured against the clean text. */
    public function testTheRangeIsMeasuredAgainstTheTextTheReaderSaw(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Start.'),
            $this->utterance(1000, 2000, 'SPEAKER_01', SpeakerRole::CUSTOMER, '>> Okay, hold on. >>'),
        ]);

        // The reader sees "Okay, hold on." — "hold on." begins at codepoint 6 of that.
        $moved = $turns->mergeSelection(1, MergeDirection::Previous, 6, 14, 'hold on.');

        $this->assertSame('Start. hold on.', $moved->turns[0]->text);
        $this->assertSame('Okay,', $moved->turns[1]->text);
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'negative start' => [-1, 5, 'So lo'];
        yield 'end before start' => [10, 4, 'x'];
        yield 'end past the text' => [0, 999, 'x'];
        yield 'empty range' => [4, 4, ''];
    }

    /**
     * @dataProvider invalidRanges
     */
    public function testAnImpossibleRangeIsRefused(int $start, int $end, string $selected): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->mergeSelection(1, MergeDirection::Previous, $start, $end, $selected);
    }

    /** The checksum: if the page and the stored turn disagree, the range means something else. */
    public function testARangeWhoseTextNoLongerMatchesIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->mergeSelection(1, MergeDirection::Previous, 0, 2, 'words never said');
    }

    public function testTheFirstTurnHasNoPreviousToMoveInto(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->mergeSelection(0, MergeDirection::Previous, 0, 5, 'Hello');
    }

    public function testTheLastTurnHasNoNextToMoveInto(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->mergeSelection(2, MergeDirection::Next, 0, 8, 'Anything');
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function range(int $index, string $needle): array
    {
        $text = $this->conversation()->turns[$index]->text;
        $start = mb_strpos($text, $needle);

        return [(int) $start, (int) $start + mb_strlen($needle), $needle];
    }

    private function conversation(): ReviewedConversationTurns
    {
        return ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Hello there.'),
            $this->utterance(2000, 4000, 'SPEAKER_01', SpeakerRole::CUSTOMER, 'So lo mein with shrimp fried rice,'),
            $this->utterance(5000, 6000, 'SPEAKER_00', SpeakerRole::AGENT, 'Anything else?'),
        ]);
    }

    private function utterance(
        int $startMs,
        int $endMs,
        string $speaker,
        SpeakerRole $role,
        string $text,
    ): SpeakerUtterance {
        return new SpeakerUtterance($startMs, $endMs, $speaker, $role, $text, 1.0);
    }
}
