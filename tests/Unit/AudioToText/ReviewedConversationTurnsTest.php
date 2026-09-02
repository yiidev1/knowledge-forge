<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\MergeRefusal;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function str_contains;

/**
 * The correction rules, with no database and no clock.
 *
 * Every operation returns a new value, so a refused change cannot leave a half-applied conversation
 * behind — and each refusal is asserted, because quietly approximating an administrator's instruction
 * would be worse than declining it in a feature whose purpose is to record their judgement.
 */
final class ReviewedConversationTurnsTest extends TestCase
{
    // ---------------------------------------------------------------- move

    public function testMovingATurnChangesOnlyItsRole(): void
    {
        $turns = $this->conversation()->moveTo(0, SpeakerRole::AGENT);

        $moved = $turns->turns[0];
        $this->assertSame(SpeakerRole::AGENT, $moved->role);
        // Everything else is exactly as it was: who said it changed, not what or when.
        $this->assertSame('Yes. For pickup', $moved->text);
        $this->assertSame(0, $moved->startMs);
        $this->assertSame(2000, $moved->endMs);
        $this->assertSame('SPEAKER_00', $moved->speaker);
        $this->assertFalse($moved->edited);
        $this->assertFalse($moved->approx);
    }

    public function testMovingToTheRoleItAlreadyHasIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTo(0, SpeakerRole::CUSTOMER);
    }

    public function testATurnCanOnlyBeMovedToAgentOrCustomer(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTo(0, SpeakerRole::OTHER);
    }

    // ---------------------------------------------------------------- split

    /** The motivating case: "Yes." is the customer, "For pickup" belongs to the agent. */
    public function testSplittingProducesTwoTurnsWhoseTextRejoinsToTheOriginal(): void
    {
        $turns = $this->conversation()->splitAt(0, 5);

        $this->assertSame(3, $turns->count());
        $this->assertSame('Yes.', $turns->turns[0]->text);
        $this->assertSame('For pickup', $turns->turns[1]->text);
        // The rest of the conversation keeps its place and its order.
        $this->assertSame('or delivery?', $turns->turns[2]->text);
    }

    /**
     * Token-level timestamps are not persisted, so there is nothing to divide. Both halves keep the
     * parent's range and say so, rather than carrying a plausible-looking number nobody measured.
     */
    public function testBothHalvesInheritTheParentRangeAndAreMarkedApproximate(): void
    {
        $turns = $this->conversation()->splitAt(0, 5);

        foreach ([$turns->turns[0], $turns->turns[1]] as $half) {
            $this->assertSame(0, $half->startMs);
            $this->assertSame(2000, $half->endMs);
            $this->assertTrue($half->approx, 'A split boundary is not a measured one.');
        }
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidOffsets(): iterable
    {
        yield 'before the text' => [0];
        yield 'negative' => [-1];
        yield 'at the very end' => [15];
        yield 'past the end' => [99];
    }

    /**
     * @dataProvider invalidOffsets
     */
    public function testSplittingOutsideTheTextIsRefused(int $offset): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->splitAt(0, $offset);
    }

    /** Splitting off nothing but whitespace is not a correction of anything. */
    public function testASplitThatWouldLeaveAnEmptyTurnIsRefused(): void
    {
        // Trailing whitespace inside the turn: the offset is legally inside the string, but everything
        // after it trims away to nothing.
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.    '),
        ]);

        $this->expectException(ReviewRejected::class);
        $turns->splitAt(0, 4);
    }

    // ---------------------------------------------------------------- merge

    public function testMergingJoinsTextAndSpansTheWholeRange(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.'),
            $this->utterance(1500, 2600, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'For pickup'),
        ])->mergeWithNext(0);

        $this->assertSame(1, $turns->count());
        $this->assertSame('Yes. For pickup', $turns->turns[0]->text);
        // Exact for two adjacent turns — no approximation is introduced by joining them.
        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(2600, $turns->turns[0]->endMs);
        $this->assertFalse($turns->turns[0]->approx);
    }

    public function testMergingWithPreviousIsTheSameJoinFromTheOtherSide(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.'),
            $this->utterance(1500, 2600, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'For pickup'),
        ])->mergeWithPrevious(1);

        $this->assertSame(1, $turns->count());
        $this->assertSame('Yes. For pickup', $turns->turns[0]->text);
    }

    /**
     * A manual merge asks only that the two turns be adjacent.
     *
     * The diarizer's view of who was speaking is exactly what an administrator is here to correct, so
     * refusing them on those grounds would be the machine overruling the reviewer.
     */
    public function testTurnsByDifferentVoicesCanBeMergedByHand(): void
    {
        $turns = $this->conversation()->mergeWithNext(0);

        $this->assertSame(1, $turns->count());
        $this->assertSame('Yes. For pickup or delivery?', $turns->turns[0]->text);
        // The joined turn keeps the first one's speaker and role.
        $this->assertSame('SPEAKER_00', $turns->turns[0]->speaker);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[0]->role);
    }

    public function testTurnsWithDifferentRolesCanBeMergedByHand(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'one'),
            $this->utterance(1000, 2000, 'SPEAKER_00', SpeakerRole::AGENT, 'two'),
        ])->mergeWithNext(0);

        $this->assertSame(1, $turns->count());
        $this->assertSame('one two', $turns->turns[0]->text);
    }

    /** Merging into the previous turn puts the earlier words first. */
    public function testMergingWithThePreviousTurnKeepsSpokenOrder(): void
    {
        $turns = $this->conversation()->mergeWithPrevious(1);

        $this->assertSame(1, $turns->count());
        $this->assertSame('Yes. For pickup or delivery?', $turns->turns[0]->text);
        // The span covers both: min of the starts, max of the ends.
        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(3000, $turns->turns[0]->endMs);
    }

    public function testMergingAtTheEdgesOfTheThreadIsRefused(): void
    {
        $conversation = $this->conversation();

        $refused = 0;

        foreach ([[0, 'previous'], [1, 'next']] as [$index, $direction]) {
            try {
                $direction === 'previous'
                    ? $conversation->mergeWithPrevious($index)
                    : $conversation->mergeWithNext($index);
            } catch (ReviewRejected) {
                ++$refused;
            }
        }

        $this->assertSame(2, $refused, 'Neither end of the thread has a neighbour on that side.');
    }

    /** A merged turn inherits approximation rather than losing it. */
    public function testMergingAnApproximateTurnKeepsTheMarker(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes. For pickup'),
        ])->splitAt(0, 5)->mergeWithNext(0);

        $this->assertSame(1, $turns->count());
        $this->assertTrue($turns->turns[0]->approx);
    }

    // ---------------------------------------------------------------- edit text

    public function testEditingTextChangesTheWordsAndMarksTheTurn(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes. For pikup'),
        ])->editText(0, 'Yes. For pickup');

        $edited = $turns->turns[0];
        $this->assertSame('Yes. For pickup', $edited->text);
        $this->assertTrue($edited->edited);
        // The words changed, not when they were said.
        $this->assertSame(0, $edited->startMs);
        $this->assertSame(2000, $edited->endMs);
        $this->assertFalse($edited->approx);
    }

    public function testEmptyOrUnchangedTextIsRefused(): void
    {
        $refused = 0;

        foreach (['', '   ', 'Yes. For pickup'] as $candidate) {
            try {
                $this->conversation()->editText(0, $candidate);
            } catch (ReviewRejected) {
                ++$refused;
            }
        }

        $this->assertSame(3, $refused);
    }

    // ---------------------------------------------------------------- indices, encoding, derivation

    public function testEveryOperationRefusesAnIndexOutsideTheThread(): void
    {
        $conversation = $this->conversation();
        $refused = 0;

        foreach ([-1, 99] as $index) {
            foreach (['move', 'split', 'edit'] as $operation) {
                try {
                    match ($operation) {
                        'move' => $conversation->moveTo($index, SpeakerRole::AGENT),
                        'split' => $conversation->splitAt($index, 2),
                        default => $conversation->editText($index, 'something'),
                    };
                } catch (ReviewRejected) {
                    ++$refused;
                }
            }
        }

        $this->assertSame(6, $refused);
    }

    public function testTurnsSurviveAJsonRoundTrip(): void
    {
        $original = $this->conversation()->splitAt(0, 5)->editText(1, 'For pick-up');

        $restored = ReviewedConversationTurns::fromJson($original->toJson());

        $this->assertSame($original->count(), $restored->count());
        $this->assertSame('For pick-up', $restored->turns[1]->text);
        $this->assertTrue($restored->turns[1]->approx);
        $this->assertTrue($restored->turns[1]->edited);
    }

    /**
     * An untouched turn must serialise exactly like a machine-written one, so the reviewed column keeps
     * the same shape as `speaker_segments` and the existing decoder stays unaware of this class.
     */
    public function testAnUntouchedTurnCarriesNoExtraKeys(): void
    {
        $decoded = json_decode($this->conversation()->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('approx', $decoded[0]);
        $this->assertArrayNotHasKey('edited', $decoded[0]);
    }

    public function testReviewedJsonIsReadableByTheExistingDecoder(): void
    {
        $json = $this->conversation()->splitAt(0, 5)->toJson();

        $utterances = (new SpeakerSegmentsDecoder())->decode($json);

        $this->assertCount(3, $utterances);
        $this->assertSame('Yes.', $utterances[0]->text);
    }

    /** Derivation must match the pipeline's own, or one conversation would read two ways. */
    public function testRoleTextIsAssembledLineByLineInOrder(): void
    {
        $turns = $this->conversation()->splitAt(0, 5)->moveTo(1, SpeakerRole::AGENT);

        $this->assertSame('Yes.', $turns->textFor(SpeakerRole::CUSTOMER));
        $this->assertSame("For pickup\nor delivery?", $turns->textFor(SpeakerRole::AGENT));
    }

    public function testDerivationSkipsBlankTurns(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, '   '),
            $this->utterance(1000, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.'),
        ]);

        $this->assertSame('Yes.', $turns->textFor(SpeakerRole::CUSTOMER));
    }

    public function testMalformedJsonYieldsAnEmptyConversationRatherThanThrowing(): void
    {
        $this->assertTrue(ReviewedConversationTurns::fromJson('{not json')->isEmpty());
        $this->assertTrue(ReviewedConversationTurns::fromJson(null)->isEmpty());
    }

    /** Nothing reorders turns, so the conversation stays in the sequence it was spoken. */
    public function testOrderIsNeverChanged(): void
    {
        $turns = $this->conversation()->splitAt(0, 5)->moveTo(2, SpeakerRole::CUSTOMER);

        $text = '';
        foreach ($turns->turns as $turn) {
            $text .= $turn->text . '|';
        }

        $this->assertTrue(str_contains($text, 'Yes.|For pickup|or delivery?|'));
    }

    /**
     * "Yes. For pickup" said by the customer, then "or delivery?" by the agent — the shape of the
     * misassignment this feature exists to correct.
     */
    // ---------------------------------------------------------------- move a selection

    /**
     * The whole point of the composition: a fragment is reassigned without inventing a timestamp,
     * relocating words in time, or emptying the turn it came from.
     */
    public function testMovingAWholeTurnIsJustAMove(): void
    {
        $turns = $this->conversation()->moveTextTo(0, 'Yes. For pickup', SpeakerRole::AGENT);

        $this->assertCount(2, $turns->turns, 'No split is needed for a whole turn.');
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[0]->role);
        $this->assertFalse($turns->turns[0]->approx, 'Nothing was cut, so nothing is approximate.');
    }

    public function testAnUntrimmedWholeSelectionIsStillAWholeTurn(): void
    {
        $turns = $this->conversation()->moveTextTo(0, "  Yes. For pickup \n", SpeakerRole::AGENT);

        $this->assertCount(2, $turns->turns);
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[0]->role);
    }

    public function testMovingTheStartOfATurnSplitsOnceAndReassignsTheFirstHalf(): void
    {
        $turns = $this->conversation()->moveTextTo(0, 'Yes.', SpeakerRole::AGENT);

        $this->assertCount(3, $turns->turns);
        $this->assertSame('Yes.', $turns->turns[0]->text);
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[0]->role);
        $this->assertSame('For pickup', $turns->turns[1]->text);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[1]->role, 'The remainder is untouched.');
        // Both halves inherit the parent span rather than claiming a boundary nobody measured.
        $this->assertTrue($turns->turns[0]->approx);
        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(2000, $turns->turns[0]->endMs);
    }

    public function testMovingTheEndOfATurnSplitsOnceAndReassignsTheSecondHalf(): void
    {
        $turns = $this->conversation()->moveTextTo(0, 'For pickup', SpeakerRole::AGENT);

        $this->assertCount(3, $turns->turns);
        $this->assertSame('Yes.', $turns->turns[0]->text);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[0]->role);
        $this->assertSame('For pickup', $turns->turns[1]->text);
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[1]->role);
    }

    public function testMovingTheMiddleOfATurnSplitsTwice(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Well hello there friend'),
        ])->moveTextTo(0, 'hello there', SpeakerRole::AGENT);

        $this->assertCount(3, $turns->turns);
        $this->assertSame('Well', $turns->turns[0]->text);
        $this->assertSame('hello there', $turns->turns[1]->text);
        $this->assertSame('friend', $turns->turns[2]->text);
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[1]->role);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[0]->role);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[2]->role);
    }

    /** Codepoints, not UTF-16 units: an emoji must not shift the cut by one. */
    public function testASelectionAfterAnEmojiIsCutInTheRightPlace(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Great 👍 thanks a lot'),
        ])->moveTextTo(0, 'thanks a lot', SpeakerRole::AGENT);

        $this->assertCount(2, $turns->turns);
        $this->assertSame('Great 👍', $turns->turns[0]->text);
        $this->assertSame('thanks a lot', $turns->turns[1]->text);
        $this->assertSame(SpeakerRole::AGENT, $turns->turns[1]->role);
    }

    public function testAnEmojiCanItselfBeMoved(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Great 👍 thanks'),
        ])->moveTextTo(0, '👍 thanks', SpeakerRole::AGENT);

        $this->assertSame('Great', $turns->turns[0]->text);
        $this->assertSame('👍 thanks', $turns->turns[1]->text);
    }

    /** The hint decides which repeat was highlighted; moving the wrong one would corrupt the record. */
    public function testTheHintChoosesBetweenRepeatsOfTheSameWords(): void
    {
        $repeated = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'yes ok yes please'),
        ]);

        $first = $repeated->moveTextTo(0, 'yes', SpeakerRole::AGENT, 0);
        $this->assertSame('yes', $first->turns[0]->text);
        $this->assertSame(SpeakerRole::AGENT, $first->turns[0]->role);

        $second = $repeated->moveTextTo(0, 'yes', SpeakerRole::AGENT, 7);
        $this->assertSame('yes ok', $second->turns[0]->text);
        $this->assertSame(SpeakerRole::AGENT, $second->turns[1]->role);
        $this->assertSame('yes', $second->turns[1]->text);
    }

    /** A whole-turn move joins its neighbour when both become the same voice and role. */
    public function testAWholeTurnMoveMergesIntoAMatchingNeighbour(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'Hello there.'),
            $this->utterance(1000, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'How can I help?'),
        ])->moveTextTo(1, 'How can I help?', SpeakerRole::AGENT);

        $this->assertCount(1, $turns->turns, 'Same voice and role: one bubble, not two.');
        $this->assertSame('Hello there. How can I help?', $turns->turns[0]->text);
        $this->assertSame(0, $turns->turns[0]->startMs);
        $this->assertSame(2000, $turns->turns[0]->endMs);
    }

    /** And refuses to when the diarizer heard two different voices — the safeguard, still on. */
    public function testAMoveDoesNotMergeAcrossDifferentVoices(): void
    {
        $turns = $this->conversation()->moveTextTo(1, 'or delivery?', SpeakerRole::CUSTOMER);

        $this->assertCount(2, $turns->turns);
        $this->assertSame(SpeakerRole::CUSTOMER, $turns->turns[1]->role);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTextTo(0, "   \n  ", SpeakerRole::AGENT);
    }

    public function testASelectionThatIsNotInTheTurnIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTextTo(0, 'words that were never said', SpeakerRole::AGENT);
    }

    public function testMovingAWholeTurnToTheRoleItAlreadyHasIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTextTo(0, 'Yes. For pickup', SpeakerRole::CUSTOMER);
    }

    public function testMovingToAnUnsupportedRoleIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTextTo(0, 'Yes.', SpeakerRole::OTHER);
    }

    public function testAnOutOfRangeTurnIsRefused(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->conversation()->moveTextTo(9, 'Yes.', SpeakerRole::AGENT);
    }

    /** Whatever the route through the composition, the words themselves survive intact. */
    public function testNoWordsAreLostByAnySelection(): void
    {
        $text = 'Well hello there friend';
        $original = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, $text),
        ]);

        foreach (['Well', 'hello', 'there friend', 'hello there', $text] as $selection) {
            $moved = $original->moveTextTo(0, $selection, SpeakerRole::AGENT);

            $rejoined = '';
            foreach ($moved->turns as $turn) {
                $rejoined .= ($rejoined === '' ? '' : ' ') . $turn->text;
            }

            $this->assertSame($text, $rejoined, 'Selection: ' . $selection);
        }
    }

    // ---------------------------------------------------------------- merge availability

    /**
     * The guard against the page and the service drifting apart.
     *
     * `mergeAvailability()` exists so a disabled control can explain itself. If it ever disagreed with
     * `merge()`, the page would offer a merge the service refuses — or hide one it would have allowed.
     */
    public function testMergeAvailabilityAgreesWithMergeOnEveryTurnAndDirection(): void
    {
        $conversations = [
            'two speakers' => $this->conversation(),
            'split halves' => $this->conversation()->splitAt(0, 5),
            'same role, two voices' => $this->conversation()->moveTo(1, SpeakerRole::CUSTOMER),
        ];

        foreach ($conversations as $name => $conversation) {
            for ($index = 0; $index < $conversation->count(); $index++) {
                foreach (MergeDirection::cases() as $direction) {
                    // The manual predicate, because these are the operations a person invokes.
                    $allowed = $conversation->manualMergeAvailability($index, $direction)->isAllowed();

                    $accepted = true;

                    try {
                        $direction === MergeDirection::Previous
                            ? $conversation->mergeWithPrevious($index)
                            : $conversation->mergeWithNext($index);
                    } catch (ReviewRejected) {
                        $accepted = false;
                    }

                    $this->assertSame(
                        $allowed,
                        $accepted,
                        $name . ": availability and the operation disagree at turn {$index} ({$direction->value})",
                    );
                }
            }
        }
    }

    public function testTheEdgesOfTheThreadHaveNoNeighbour(): void
    {
        $conversation = $this->conversation();

        $this->assertSame(MergeRefusal::NoNeighbour, $conversation->mergeAvailability(0, MergeDirection::Previous));
        $this->assertSame(MergeRefusal::NoNeighbour, $conversation->mergeAvailability(1, MergeDirection::Next));
    }

    /** The refusal the page most needs to explain: nothing on screen distinguishes these two turns. */
    public function testTwoVoicesSharingARoleRefuseToMergeAndSayWhy(): void
    {
        $conversation = $this->conversation()->moveTo(1, SpeakerRole::CUSTOMER);

        $refusal = $conversation->mergeAvailability(0, MergeDirection::Next);

        $this->assertSame(MergeRefusal::DifferentSpeaker, $refusal);
        $this->assertStringContainsString('two different voices', (string) $refusal->reason());
    }

    public function testDifferingRolesAreNamedAsTheReasonBeforeDifferingVoices(): void
    {
        // The fixture differs in both. Role is reported because it is the one an administrator can see.
        $this->assertSame(
            MergeRefusal::DifferentRole,
            $this->conversation()->mergeAvailability(0, MergeDirection::Next),
        );
    }

    public function testSplitHalvesCanAlwaysBeRejoined(): void
    {
        $split = $this->conversation()->splitAt(0, 5);

        $this->assertTrue($split->mergeAvailability(0, MergeDirection::Next)->isAllowed());
        $this->assertSame('Yes. For pickup', $split->mergeWithNext(0)->turns[0]->text);
    }

    // ---------------------------------------------------------------- confirmation precondition

    public function testAConversationWithBothRolesMayBeConfirmed(): void
    {
        $this->assertTrue($this->conversation()->hasBothRoles());
    }

    public function testAOneSidedConversationMayNotBeConfirmed(): void
    {
        // Every turn on one side is not a split, and publishing it would put an empty block on screen
        // under a heading claiming otherwise.
        $this->assertFalse($this->conversation()->moveTo(1, SpeakerRole::CUSTOMER)->hasBothRoles());
    }

    public function testARoleWhoseOnlyTurnIsBlankDoesNotCount(): void
    {
        $turns = ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.'),
            $this->utterance(2100, 3000, 'SPEAKER_01', SpeakerRole::AGENT, '   '),
        ]);

        // textFor() skips blank turns, so "a turn exists" is not the same as "there is text".
        $this->assertFalse($turns->hasBothRoles());
    }

    private function conversation(): ReviewedConversationTurns
    {
        return ReviewedConversationTurns::fromUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes. For pickup'),
            $this->utterance(2100, 3000, 'SPEAKER_01', SpeakerRole::AGENT, 'or delivery?'),
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
