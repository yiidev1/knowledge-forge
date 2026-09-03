<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\ConversationDiff;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\SpeakerRole;
use Codeception\Test\Unit;

use function count;
use function json_encode;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * The property the whole correction history rests on.
 *
 * History is derived, not recorded: a revision stores the conversation before an operation and nothing
 * about which message it touched, so the affected message is recovered by diffing consecutive snapshots
 * ({@see ConversationDiff}). That recovery is exact for one reason only — **every operation replaces a
 * single contiguous run of turns and leaves every other turn byte-identical.**
 *
 * Nothing in the type system enforces that. It is true today because each operation rebuilds the list
 * around one edited region and copies the rest, and it was verified against all 112 corrections recorded
 * in this installation. But an operation added later that reordered turns, or touched two separate
 * places, would silently widen the derived run and make the history attribute a change to messages it
 * never touched — a wrong record, which is worse than no record.
 *
 * So the property is asserted here, per operation. If this test fails, the history feature is reporting
 * fiction and the diff strategy needs revisiting before the operation ships.
 */
final class ReviewedConversationTurnsContiguityTest extends Unit
{
    /** Every operation below must leave the run it changed no wider than this. */
    private const WIDEST_EXPECTED_RUN = 3;

    public function testAWholeTurnMoveChangesOnlyThatTurn(): void
    {
        $before = $this->conversation();
        $after = $before->moveTo(2, SpeakerRole::AGENT);

        $this->assertContiguous($before, $after, expectedPrefix: 2, expectedBefore: 1, expectedAfter: 1);
    }

    public function testASplitReplacesOneTurnWithTwo(): void
    {
        $before = $this->conversation();
        $after = $before->splitAt(1, 5);

        $this->assertContiguous($before, $after, expectedPrefix: 1, expectedBefore: 1, expectedAfter: 2);
    }

    public function testAMergeWithTheNextTurnReplacesTwoWithOne(): void
    {
        $before = $this->conversation();
        $after = $before->mergeWithNext(1);

        $this->assertContiguous($before, $after, expectedPrefix: 1, expectedBefore: 2, expectedAfter: 1);
    }

    public function testAMergeWithThePreviousTurnReplacesTwoWithOne(): void
    {
        $before = $this->conversation();
        $after = $before->mergeWithPrevious(2);

        $this->assertContiguous($before, $after, expectedPrefix: 1, expectedBefore: 2, expectedAfter: 1);
    }

    public function testAWordingCorrectionChangesOnlyThatTurn(): void
    {
        $before = $this->conversation();
        $after = $before->editText(3, 'corrected wording');

        $this->assertContiguous($before, $after, expectedPrefix: 3, expectedBefore: 1, expectedAfter: 1);
    }

    /**
     * Moving a selection is composed of a split, a move and possibly a merge, and is the operation most
     * likely to break the property — so it is asserted for a selection at the start, at the end and in
     * the middle of a turn.
     *
     * @dataProvider selections
     */
    public function testMovingASelectionChangesOneContiguousRun(string $selection): void
    {
        $before = $this->conversation();
        $after = $before->moveTextTo(2, $selection, SpeakerRole::AGENT);

        $diff = ConversationDiff::between($before->turns, $after->turns);

        $this->assertUntouchedOutsideTheRun($before, $after, $diff);
        assertLessThanOrEqual(self::WIDEST_EXPECTED_RUN, $diff->lengthBefore, 'run before');
        assertLessThanOrEqual(self::WIDEST_EXPECTED_RUN, $diff->lengthAfter, 'run after');
    }

    /** @return iterable<string, array{0: string}> */
    public function selections(): iterable
    {
        yield 'whole turn' => ['Anything else for you today'];
        yield 'leading words' => ['Anything else'];
        yield 'trailing words' => ['for you today'];
        yield 'middle words' => ['else for'];
    }

    /**
     * Joining part of one turn to its neighbour touches both and must still be one run.
     *
     * This is the shape the history feature reports at region level rather than per message — it may
     * change two turns into two different turns, and nothing in the data says which came from which.
     */
    public function testMergingASelectionChangesOneContiguousRun(): void
    {
        $before = $this->conversation();
        $after = $before->mergeSelection(2, MergeDirection::Previous, 0, 13, 'Anything else');

        $diff = ConversationDiff::between($before->turns, $after->turns);

        $this->assertUntouchedOutsideTheRun($before, $after, $diff);
        assertLessThanOrEqual(self::WIDEST_EXPECTED_RUN, $diff->lengthBefore, 'run before');
        assertLessThanOrEqual(self::WIDEST_EXPECTED_RUN, $diff->lengthAfter, 'run after');
    }

    /** The empty diff every confirmation produces — nothing changed, so nothing may be attributed. */
    public function testAnUnchangedConversationHasAnEmptyRun(): void
    {
        $conversation = $this->conversation();
        $roundTripped = ReviewedConversationTurns::fromJson($conversation->toJson());

        $diff = ConversationDiff::between($conversation->turns, $roundTripped->turns);

        assertSame(true, $diff->isEmpty(), 'A conversation compared with itself must show no change.');
    }

    // ---------------------------------------------------------------- helpers

    private function assertContiguous(
        ReviewedConversationTurns $before,
        ReviewedConversationTurns $after,
        int $expectedPrefix,
        int $expectedBefore,
        int $expectedAfter,
    ): void {
        $diff = ConversationDiff::between($before->turns, $after->turns);

        assertSame($expectedPrefix, $diff->prefix, 'the run must start where the operation did');
        assertSame($expectedBefore, $diff->lengthBefore, 'turns replaced');
        assertSame($expectedAfter, $diff->lengthAfter, 'turns produced');

        $this->assertUntouchedOutsideTheRun($before, $after, $diff);
    }

    /**
     * The load-bearing half: everything outside the run is the same turn, byte for byte.
     *
     * Compared through the stored JSON rather than field by field, because that is what a revision holds
     * and therefore what the diff will actually see.
     */
    private function assertUntouchedOutsideTheRun(
        ReviewedConversationTurns $before,
        ReviewedConversationTurns $after,
        ConversationDiff $diff,
    ): void {
        for ($i = 0; $i < $diff->prefix; $i++) {
            assertSame(
                json_encode($before->turns[$i]->toArray()),
                json_encode($after->turns[$i]->toArray()),
                'turn ' . $i . ' is before the run and must be untouched',
            );
        }

        $tailBefore = count($before->turns) - ($diff->prefix + $diff->lengthBefore);
        $tailAfter = count($after->turns) - ($diff->prefix + $diff->lengthAfter);

        assertSame($tailBefore, $tailAfter, 'the run must account for every difference in length');

        for ($i = 1; $i <= $tailBefore; $i++) {
            assertSame(
                json_encode($before->turns[count($before->turns) - $i]->toArray()),
                json_encode($after->turns[count($after->turns) - $i]->toArray()),
                'the turn ' . $i . ' from the end is after the run and must be untouched',
            );
        }
    }

    /** Four alternating turns — enough for a prefix, a run and a suffix in every direction. */
    private function conversation(): ReviewedConversationTurns
    {
        return ReviewedConversationTurns::fromJson((string) json_encode([
            $this->turn(0, 1000, 'SPEAKER_00', 'CUSTOMER', 'Hi can I place an order'),
            $this->turn(1100, 2000, 'SPEAKER_01', 'AGENT', 'Sure for pickup or delivery'),
            $this->turn(2100, 3000, 'SPEAKER_00', 'CUSTOMER', 'Anything else for you today'),
            $this->turn(3100, 4000, 'SPEAKER_01', 'AGENT', 'That will be twelve fifty'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function turn(int $start, int $end, string $speaker, string $role, string $text): array
    {
        return [
            'start_ms' => $start,
            'end_ms' => $end,
            'speaker' => $speaker,
            'role' => $role,
            'text' => $text,
            'confidence' => 0.9,
        ];
    }
}
