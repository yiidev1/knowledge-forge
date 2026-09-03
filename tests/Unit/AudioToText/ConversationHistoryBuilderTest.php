<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\ConversationHistoryBuilder;
use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevision;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\TurnLineage;
use App\AudioToText\Domain\SpeakerRole;
use Codeception\Test\Unit;
use DateTimeImmutable;

use function count;
use function json_encode;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Deriving each message's history from the audit trail.
 *
 * The revisions are built the way the service builds them — by running the real operations on a real
 * conversation and recording the state before each — so these are not hand-written fixtures asserting
 * the builder's own assumptions back at it. If an operation ever stopped changing one contiguous run,
 * these would fail alongside the contiguity guard rather than agreeing with a broken premise.
 */
final class ConversationHistoryBuilderTest extends Unit
{
    private ConversationHistoryBuilder $builder;

    protected function _before(): void
    {
        $this->builder = new ConversationHistoryBuilder();
    }

    /** A conversation nobody has corrected has nothing to show. */
    public function testAnUncorrectedConversationHasNoHistory(): void
    {
        $current = $this->conversation();

        $lineages = $this->builder->build([], $current);

        assertCount(count($current->turns), $lineages);
        $this->assertNoneHaveHistory($lineages);
    }

    /** H. A wording correction lands on exactly the turn it changed, and on no other. */
    public function testAWordingCorrectionIsAttributedToOneTurn(): void
    {
        $before = $this->conversation();
        $after = $before->editText(1, 'Sure, for pickup or delivery?');

        $lineages = $this->build([[$before, ReviewOperation::EditText]], $after);

        $this->assertOnlyTurnsHaveHistory([1], $lineages);

        $event = $lineages[1]->events[0];
        assertSame(ReviewOperation::EditText, $event->operation);
        assertSame('Wording corrected', $event->summary());
        assertSame('Sure for pickup or delivery', $event->before[0]->text);
        assertSame('Sure, for pickup or delivery?', $event->after[0]->text);
        assertFalse($event->involvesSeveralTurns());
        // M. Its neighbours are untouched and say so.
        assertTrue($lineages[0]->isEmpty());
        assertTrue($lineages[2]->isEmpty());
    }

    /** I. A whole-turn move records the role it had, not a text change. */
    public function testAWholeTurnMoveIsAttributedToOneTurn(): void
    {
        $before = $this->conversation();
        $after = $before->moveTo(2, SpeakerRole::AGENT);

        $lineages = $this->build([[$before, ReviewOperation::Move]], $after);

        $this->assertOnlyTurnsHaveHistory([2], $lineages);

        $event = $lineages[2]->events[0];
        assertSame('Speaker reassigned', $event->summary());
        assertSame(SpeakerRole::CUSTOMER, $event->before[0]->role);
        assertSame(SpeakerRole::AGENT, $event->after[0]->role);
    }

    /** J. Both halves of a split point back at the one message they came from. */
    public function testASplitGivesBothChildrenTheOneParent(): void
    {
        $before = $this->conversation();
        $after = $before->splitAt(1, 5);

        $lineages = $this->build([[$before, ReviewOperation::Split]], $after);

        $this->assertOnlyTurnsHaveHistory([1, 2], $lineages);

        foreach ([1, 2] as $index) {
            $event = $lineages[$index]->events[0];
            assertSame('Message split in two', $event->summary());
            assertCount(1, $event->before, 'one parent');
            assertCount(2, $event->after, 'two children');
            assertSame('Sure for pickup or delivery', $event->before[0]->text);
        }
    }

    /** K. The merged message names both of the messages that produced it. */
    public function testAMergeGivesTheResultBothParents(): void
    {
        $before = $this->conversation();
        $after = $before->mergeWithNext(1);

        $lineages = $this->build([[$before, ReviewOperation::Merge]], $after);

        $this->assertOnlyTurnsHaveHistory([1], $lineages);

        $event = $lineages[1]->events[0];
        assertSame('Messages joined', $event->summary());
        assertCount(2, $event->before);
        assertCount(1, $event->after);
        assertTrue($event->involvesSeveralTurns());
    }

    /**
     * L. Two messages in, two out — the shape where ancestry is genuinely unknowable.
     *
     * Both current messages carry the *same* event, and that event names all four messages. Nothing
     * claims which result came from which source, because the stored data cannot show it.
     */
    public function testARangeMergeIsOneSharedEventOnBothTurns(): void
    {
        $before = $this->conversation();
        $after = $before->mergeSelection(2, MergeDirection::Previous, 0, 13, 'Anything else');

        $lineages = $this->build([[$before, ReviewOperation::Merge]], $after);

        $this->assertOnlyTurnsHaveHistory([1, 2], $lineages);

        $first = $lineages[1]->events[0];
        $second = $lineages[2]->events[0];

        assertSame($first->revisionNumber, $second->revisionNumber, 'one event, shown on both messages');
        assertSame('Text moved between two messages', $first->summary());
        assertCount(2, $first->before);
        assertCount(2, $first->after);
    }

    /** Corrections accumulate: two edits to one message show both, newest first. */
    public function testRepeatedCorrectionsAccumulateNewestFirst(): void
    {
        $first = $this->conversation();
        $second = $first->editText(1, 'Sure, for pickup or delivery');
        $third = $second->editText(1, 'Sure, for pickup or delivery?');

        $lineages = $this->build(
            [[$first, ReviewOperation::EditText], [$second, ReviewOperation::EditText]],
            $third,
        );

        assertCount(2, $lineages[1]->events);
        assertSame(2, $lineages[1]->events[0]->revisionNumber, 'newest first');
        assertSame(1, $lineages[1]->events[1]->revisionNumber);
        // Each event carries the wording as it stood before that particular correction.
        assertSame('Sure, for pickup or delivery', $lineages[1]->events[0]->before[0]->text);
        assertSame('Sure for pickup or delivery', $lineages[1]->events[1]->before[0]->text);
    }

    /** History survives a structural change: a split child keeps what its parent had collected. */
    public function testASplitChildInheritsItsParentsHistory(): void
    {
        $first = $this->conversation();
        $second = $first->editText(1, 'Sure for pickup or delivery today');
        $third = $second->splitAt(1, 5);

        $lineages = $this->build(
            [[$first, ReviewOperation::EditText], [$second, ReviewOperation::Split]],
            $third,
        );

        foreach ([1, 2] as $index) {
            assertCount(2, $lineages[$index]->events, 'the split, and the edit that preceded it');
            assertSame(ReviewOperation::Split, $lineages[$index]->events[0]->operation);
            assertSame(ReviewOperation::EditText, $lineages[$index]->events[1]->operation);
        }
    }

    /** N. Confirming roles changes no message, so it attaches to none. */
    public function testConfirmingRolesProducesNoMessageHistory(): void
    {
        $conversation = $this->conversation();

        $lineages = $this->build([[$conversation, ReviewOperation::ConfirmRoles]], $conversation);

        $this->assertNoneHaveHistory($lineages);
    }

    /**
     * O. A revert throws the corrected conversation away, so nothing before it describes what is on
     * screen now. A job whose last correction was a revert has no message history at all.
     */
    public function testNothingBeforeTheLastRevertIsAttributed(): void
    {
        $machine = $this->conversation();
        $edited = $machine->editText(1, 'Sure, for pickup or delivery?');

        $lineages = $this->build(
            [[$machine, ReviewOperation::EditText], [$edited, ReviewOperation::Revert]],
            $machine,
        );

        $this->assertNoneHaveHistory($lineages);
    }

    /** P. Corrections made after a revert are attributed normally. */
    public function testCorrectionsAfterARevertAreAttributed(): void
    {
        $machine = $this->conversation();
        $edited = $machine->editText(1, 'discarded wording');
        $afterRevert = $machine->editText(2, 'Anything else today?');

        $lineages = $this->build(
            [
                [$machine, ReviewOperation::EditText],
                [$edited, ReviewOperation::Revert],
                [$machine, ReviewOperation::EditText],
            ],
            $afterRevert,
        );

        // Only the correction that came after the revert.
        $this->assertOnlyTurnsHaveHistory([2], $lineages);
        assertCount(1, $lineages[2]->events);
        assertSame(3, $lineages[2]->events[0]->revisionNumber);
        assertSame('Anything else for you today', $lineages[2]->events[0]->before[0]->text);
    }

    /** An unreadable snapshot suppresses history rather than diffing against nothing. */
    public function testAnUnreadableSnapshotYieldsNoHistoryRatherThanNonsense(): void
    {
        $current = $this->conversation();

        $lineages = $this->builder->build(
            [$this->revision(1, 'not json at all', ReviewOperation::EditText)],
            $current,
        );

        assertCount(count($current->turns), $lineages);
        $this->assertNoneHaveHistory($lineages);
    }

    /** The editor and the moment are carried through to the event. */
    public function testTheEventCarriesTheEditorAndTheMoment(): void
    {
        $before = $this->conversation();
        $after = $before->editText(0, 'Hi, can I place an order?');

        $lineages = $this->build([[$before, ReviewOperation::EditText]], $after);

        $event = $lineages[0]->events[0];
        assertSame('admin', $event->editedByUsername);
        assertSame('2026-09-03 10:00:00', $event->createdAt->format('Y-m-d H:i:s'));
        assertSame(1, $event->revisionNumber);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<array{0: ReviewedConversationTurns, 1: ReviewOperation}> $steps state before each op
     *
     * @return list<TurnLineage>
     */
    private function build(array $steps, ReviewedConversationTurns $current): array
    {
        $revisions = [];
        foreach ($steps as $index => [$state, $operation]) {
            $revisions[] = $this->revision($index + 1, $state->toJson(), $operation);
        }

        return $this->builder->build($revisions, $current);
    }

    private function revision(int $number, string $json, ReviewOperation $operation): SegmentRevision
    {
        return new SegmentRevision(
            $number,
            1,
            $number,
            $json,
            $operation,
            'admin',
            1,
            'admin',
            new DateTimeImmutable('2026-09-03 10:00:00'),
        );
    }

    /**
     * @param list<int> $expected
     * @param list<TurnLineage> $lineages
     */
    private function assertOnlyTurnsHaveHistory(array $expected, array $lineages): void
    {
        $actual = [];
        foreach ($lineages as $index => $lineage) {
            if (!$lineage->isEmpty()) {
                $actual[] = $index;
            }
        }

        assertSame($expected, $actual, 'exactly these messages may show a history icon');
    }

    /**
     * @param list<TurnLineage> $lineages
     */
    private function assertNoneHaveHistory(array $lineages): void
    {
        foreach ($lineages as $index => $lineage) {
            assertTrue($lineage->isEmpty(), 'message ' . $index . ' must show no history');
        }
    }

    private function conversation(): ReviewedConversationTurns
    {
        return ReviewedConversationTurns::fromJson((string) json_encode([
            ['start_ms' => 0, 'end_ms' => 1000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Hi can I place an order', 'confidence' => 0.9],
            ['start_ms' => 1100, 'end_ms' => 2000, 'speaker' => 'SPEAKER_01', 'role' => 'AGENT',
                'text' => 'Sure for pickup or delivery', 'confidence' => 0.9],
            ['start_ms' => 2100, 'end_ms' => 3000, 'speaker' => 'SPEAKER_00', 'role' => 'CUSTOMER',
                'text' => 'Anything else for you today', 'confidence' => 0.9],
        ]));
    }
}
