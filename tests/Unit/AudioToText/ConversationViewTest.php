<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use PHPUnit\Framework\TestCase;

/**
 * The one rule this class exists to enforce: a role name is shown only when the separation result was
 * published.
 *
 * Every case below feeds in utterances that *already carry* AGENT and CUSTOMER roles, because that is
 * what the database actually holds for an unpublished split — the role mapper assigns roles before the
 * confidence gate is evaluated, so a NEEDS_REVIEW row is byte-for-byte indistinguishable from a
 * COMPLETED one in `speaker_segments`. Only the status separates them, which is why only the status may
 * decide the labels.
 */
final class ConversationViewTest extends TestCase
{
    public function testAPublishedSplitLabelsTurnsByRole(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::COMPLETED, $this->mappedCall(), 0.72);

        self::assertTrue($view->rolesPublished);
        self::assertSame(['Agent', 'Customer', 'Agent'], $this->labels($view));

        foreach ($view->turns as $turn) {
            self::assertTrue($turn->confirmed);
        }
    }

    public function testAPublishedSplitOffersNoHypothesis(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::COMPLETED, $this->mappedCall(), 0.72);

        // A published role is a finding. Restating it as a guess would only undermine it.
        self::assertSame([], $view->hypotheses);
    }

    public function testLowRoleConfidenceFallsBackToNeutralSpeakers(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, $this->mappedCall(), 0.08);

        self::assertFalse($view->rolesPublished);
        self::assertSame(['Speaker 1', 'Speaker 2', 'Speaker 1'], $this->labels($view));

        foreach ($view->turns as $turn) {
            self::assertFalse($turn->confirmed);
            self::assertNotSame('Agent', $turn->label);
            self::assertNotSame('Customer', $turn->label);
        }
    }

    public function testNeedsReviewKeepsTheGuessButMarksItAsOne(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, $this->mappedCall(), 0.08);

        self::assertSame(['Agent' => 'Speaker 1', 'Customer' => 'Speaker 2'], $view->hypotheses);
        self::assertSame(0.08, $view->confidence);
    }

    /** The alignment-failure path: no role was ever assigned, so there is nothing to guess with. */
    public function testNeedsReviewFromPoorAlignmentShowsNeutralSpeakersAndNoHypothesis(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            [
                $this->utterance('SPEAKER_00', SpeakerRole::UNKNOWN, 'Hello?'),
                $this->utterance('SPEAKER_01', SpeakerRole::UNKNOWN, 'Yes.'),
            ],
            null,
        );

        self::assertSame(['Speaker 1', 'Speaker 2'], $this->labels($view));
        self::assertSame([], $view->hypotheses);
        self::assertNull($view->confidence);
    }

    public function testAHypothesisPointingAtOneClusterIsDropped(): void
    {
        // Both roles landing on the same voice is not a weak answer, it is no answer.
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            [
                $this->utterance('SPEAKER_00', SpeakerRole::AGENT, 'Hello?'),
                $this->utterance('SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.'),
            ],
            0.11,
        );

        self::assertSame([], $view->hypotheses);
    }

    public function testUnattributedSpeechIsNamedRatherThanNumbered(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            [$this->utterance('UNKNOWN', SpeakerRole::UNKNOWN, 'thank you bye bye')],
            null,
        );

        // "Speaker 0" would name a cluster the diarizer never produced.
        self::assertSame(['Unidentified speaker'], $this->labels($view));
    }

    public function testAnUnknownRoleInsideAPublishedSplitStaysNeutral(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::COMPLETED,
            [
                $this->utterance('SPEAKER_00', SpeakerRole::AGENT, 'Cash or card?'),
                $this->utterance('UNKNOWN', SpeakerRole::UNKNOWN, 'bye bye'),
            ],
            0.72,
        );

        self::assertSame(['Agent', 'Unidentified speaker'], $this->labels($view));
        self::assertTrue($view->turns[0]->confirmed);
        self::assertFalse($view->turns[1]->confirmed);
    }

    /**
     * @return iterable<string, array{0: SpeakerSeparationStatus|null}>
     */
    public static function unpublishableStatuses(): iterable
    {
        yield 'needs review' => [SpeakerSeparationStatus::NEEDS_REVIEW];
        yield 'failed' => [SpeakerSeparationStatus::FAILED];
        yield 'not supported' => [SpeakerSeparationStatus::NOT_SUPPORTED];
        yield 'pending' => [SpeakerSeparationStatus::PENDING];
        yield 'processing' => [SpeakerSeparationStatus::PROCESSING];
        yield 'never attempted' => [null];
    }

    /**
     * @dataProvider unpublishableStatuses
     */
    public function testNoUnpublishedStatusEverShowsARoleLabel(?SpeakerSeparationStatus $status): void
    {
        $view = ConversationView::from($status, $this->mappedCall(), 0.9);

        self::assertFalse($view->rolesPublished);

        foreach ($view->turns as $turn) {
            self::assertFalse($turn->confirmed);
            self::assertStringStartsWith('Speaker ', $turn->label);
        }
    }

    /**
     * A COMPLETED row whose aggregate columns are empty contradicts itself. The service cannot produce
     * one, but if anything ever did, the labels follow the missing text rather than the status: the
     * detail page would otherwise name roles that the list page shows as blank, which is the exact
     * disagreement this whole change exists to remove.
     */
    public function testAPublishedStatusWithNoAggregateTextIsNotTreatedAsPublished(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::COMPLETED,
            $this->mappedCall(),
            0.72,
            false,
        );

        self::assertFalse($view->rolesPublished);
        self::assertSame(['Speaker 1', 'Speaker 2', 'Speaker 1'], $this->labels($view));
    }

    public function testNothingDetectedIsEmpty(): void
    {
        self::assertTrue(ConversationView::from(SpeakerSeparationStatus::FAILED, [], null)->isEmpty());
    }

    /**
     * A conversation the mapper has already assigned roles to — which is what is stored whatever the
     * eventual status.
     *
     * @return list<SpeakerUtterance>
     */
    // ---------------------------------------------------------------- confirmation as a second route

    /**
     * The half of the amendment only the view can deliver.
     *
     * `speaker_separation_status` records what the machine concluded and is never rewritten, so without
     * this a confirmation would write correct columns that no page could read.
     */
    public function testAnAdministratorsConfirmationPublishesRolesTheMachineWouldNot(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            $this->mappedCall(),
            0.08,
            true,
            true,
        );

        $this->assertTrue($view->rolesPublished);
        $this->assertSame(['Agent', 'Customer', 'Agent'], $this->labels($view));
    }

    public function testWithoutConfirmationANeedsReviewCallStaysNeutral(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, $this->mappedCall(), 0.08, true, false);

        $this->assertFalse($view->rolesPublished);
        $this->assertSame(['Speaker 1', 'Speaker 2', 'Speaker 1'], $this->labels($view));
    }

    /**
     * Confirmation is a second route to publication, not a way around the aggregate-text invariant.
     *
     * The service will not confirm without text on both sides, so this state should be unreachable —
     * which is exactly why the gate is asserted rather than assumed.
     */
    public function testConfirmationStillRequiresAggregateText(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, $this->mappedCall(), 0.08, false, true);

        $this->assertFalse($view->rolesPublished);
    }

    // ---------------------------------------------------------------- corrected-turn markers

    public function testAnApproximateSpanIsMarkedAndPrintedOnce(): void
    {
        // Both halves of one split: the same span, inherited rather than observed.
        $view = ConversationView::from(SpeakerSeparationStatus::COMPLETED, [
            new SpeakerUtterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes.', 1.0, true),
            new SpeakerUtterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'For pickup', 1.0, true),
            new SpeakerUtterance(2100, 3000, 'SPEAKER_01', SpeakerRole::AGENT, 'Or delivery?', 1.0),
        ], 0.9);

        $this->assertSame('~00:00–00:02', $view->turns[0]->timing->rangeLabel());
        // Printing it twice would present one measurement as two.
        $this->assertNull($view->turns[1]->timing->rangeLabel());
        $this->assertSame('00:02–00:03', $view->turns[2]->timing->rangeLabel());
        $this->assertFalse($view->turns[2]->timing->approximate);
    }

    public function testAnEditedTurnIsMarkedWhateverItsLabel(): void
    {
        $edited = new SpeakerUtterance(0, 1000, 'SPEAKER_00', SpeakerRole::AGENT, 'For pickup', 1.0, false, true);

        $published = ConversationView::from(SpeakerSeparationStatus::COMPLETED, [$edited], 0.9);
        $neutral = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, [$edited], 0.08);

        $this->assertTrue($published->turns[0]->edited);
        $this->assertTrue($neutral->turns[0]->edited, 'A corrected turn is still corrected when unlabelled.');
    }

    private function mappedCall(): array
    {
        return [
            $this->utterance('SPEAKER_00', SpeakerRole::AGENT, 'Hello, would you like to place an order?'),
            $this->utterance('SPEAKER_01', SpeakerRole::CUSTOMER, 'Yes, for delivery.'),
            $this->utterance('SPEAKER_00', SpeakerRole::AGENT, 'Cash or card?'),
        ];
    }

    private function utterance(string $speaker, SpeakerRole $role, string $text): SpeakerUtterance
    {
        return new SpeakerUtterance(0, 1000, $speaker, $role, $text, 1.0);
    }

    /**
     * @return list<string>
     */
    private function labels(ConversationView $view): array
    {
        $labels = [];
        foreach ($view->turns as $turn) {
            $labels[] = $turn->label;
        }

        return $labels;
    }
}
