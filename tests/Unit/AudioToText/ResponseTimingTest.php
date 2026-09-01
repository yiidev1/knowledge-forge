<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\ConversationSide;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\ResponseTiming;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TurnTiming;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Response delays, derived from stored timestamps and never persisted.
 *
 * The tests worth reading twice are the ones about what is *not* shown. A third of the boundaries in a
 * real transcript sit exactly zero milliseconds apart, because the aligner coalesces contiguous tokens
 * and a turn change drawn inside continuous speech leaves no gap at all. Printing "0.0s response" there
 * would present a seam in the segmentation as a human replying instantly.
 */
final class ResponseTimingTest extends TestCase
{
    /** The plain case: the other speaker finished, this one began 1.4 seconds later. */
    public function testAReplyByTheOtherSpeakerIsTimedFromTheirLastWord(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 4200, 'SPEAKER_01', SpeakerRole::AGENT, 'Hello? Yes, you want to place an order?'),
            $this->utterance(5600, 7000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Yes. For pickup'),
        ]);

        self::assertNull($timings[0]->delayLabel(), 'The first turn answers nobody.');
        self::assertSame(1400, $timings[1]->delayMs);
        self::assertSame('1.4s response', $timings[1]->delayLabel());
    }

    /**
     * The rule that had to be tightened.
     *
     * When the turn immediately before is by the same speaker, this is a continuation. An earlier
     * version searched further back for the nearest *opposite* speaker, which timed the second half of
     * someone's own sentence against whatever the other person had said before it — and reported that
     * as a response. Stopping at the immediately preceding turn makes it impossible.
     */
    public function testAContinuationByTheSameSpeakerIsNeverGivenAResponseTime(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Hi there'),
            $this->utterance(3000, 5000, 'SPEAKER_01', SpeakerRole::AGENT, 'Hello, how can I help?'),
            // Same speaker again, after a long pause. A continuation, not a reply.
            $this->utterance(9000, 11000, 'SPEAKER_01', SpeakerRole::AGENT, 'Are you still there?'),
        ]);

        self::assertSame(1000, $timings[1]->delayMs);
        self::assertNull($timings[2]->delayMs, 'A continuation must carry no delay.');
        self::assertNull($timings[2]->delayLabel());
    }

    /** A gap this small is where the transcript was cut, not how fast someone spoke. */
    public function testAGapBelowTheThresholdIsNotReportedAsANumber(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(46_240, 47_450, 'SPEAKER_00', SpeakerRole::CUSTOMER, "That's it. So"),
            // Exactly contiguous — the real shape of a mid-sentence boundary in stored data.
            $this->utterance(47_450, 59_550, 'SPEAKER_01', SpeakerRole::AGENT, 'two orders of the Singapore…'),
        ]);

        self::assertSame(0, $timings[1]->delayMs);
        self::assertNull($timings[1]->delayLabel(), 'A zero gap must not read as an instant reply.');
    }

    public function testAGapJustOverTheThresholdIsReported(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 1000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'one'),
            $this->utterance(1160, 2000, 'SPEAKER_01', SpeakerRole::AGENT, 'two'),
        ]);

        self::assertSame('0.2s response', $timings[1]->delayLabel());
    }

    /** Overlapping speech is reported as such, never as a negative number or a silent zero. */
    public function testOverlappingSpeechIsLabelledRatherThanClampedSilently(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 5000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'I was saying that—'),
            $this->utterance(4200, 6000, 'SPEAKER_01', SpeakerRole::AGENT, 'sorry, go ahead'),
        ]);

        self::assertTrue($timings[1]->overlapping);
        self::assertSame('overlapping', $timings[1]->delayLabel());
        self::assertSame(0, $timings[1]->delayMs, 'Never a negative duration.');
    }

    /** Both timestamps at zero is what the decoder writes for missing values. Nothing is shown. */
    public function testATurnWithoutTimestampsShowsNothingAtAll(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 0, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'no timestamps'),
            $this->utterance(0, 0, 'SPEAKER_01', SpeakerRole::AGENT, 'also none'),
        ]);

        foreach ($timings as $timing) {
            self::assertFalse($timing->hasTimestamps());
            self::assertNull($timing->rangeLabel());
            self::assertNull($timing->delayLabel());
        }
    }

    /** Unattributed speech takes no part: it cannot receive a delay, nor be measured against. */
    public function testUnattributedSpeechIsSkippedOnBothSides(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'a question'),
            $this->utterance(2100, 2400, SpeakerRole::UNKNOWN->value, SpeakerRole::UNKNOWN, 'mm'),
            $this->utterance(3000, 5000, 'SPEAKER_01', SpeakerRole::AGENT, 'an answer'),
        ]);

        self::assertNull($timings[1]->delayLabel(), 'Unattributed speech gets no timing of its own.');
        // Measured from the customer's last word at 2000, not from the interjection at 2400.
        self::assertSame(1000, $timings[2]->delayMs);
    }

    /** Timestamps render as a clock range a reader can find in the recording. */
    public function testTheRangeIsShownAsMinutesAndSeconds(): void
    {
        $timings = ResponseTiming::forUtterances([
            $this->utterance(134_000, 141_000, 'SPEAKER_01', SpeakerRole::AGENT, 'Delivery in 30 minutes.'),
        ]);

        self::assertSame('02:14–02:21', $timings[0]->rangeLabel());
    }

    // ---------------------------------------------------------------- sides

    public function testAPublishedSplitPutsTheCustomerLeftAndTheAgentRight(): void
    {
        $sides = ResponseTiming::sidesFor([
            $this->utterance(0, 1000, 'SPEAKER_01', SpeakerRole::AGENT, 'hello'),
            $this->utterance(2000, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'hi'),
        ], true);

        self::assertSame(ConversationSide::Right, $sides[0]);
        self::assertSame(ConversationSide::Left, $sides[1]);
    }

    /**
     * Unconfirmed speakers still alternate sides so the exchange is readable, but the layout is the
     * only thing that alternates — the labels stay neutral, which the view enforces separately.
     */
    public function testAnUnconfirmedSplitStillAlternatesSidesByCluster(): void
    {
        $utterances = [
            $this->utterance(0, 1000, 'SPEAKER_01', SpeakerRole::AGENT, 'hello'),
            $this->utterance(2000, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'hi'),
            $this->utterance(4000, 5000, 'SPEAKER_01', SpeakerRole::AGENT, 'again'),
        ];

        $sides = ResponseTiming::sidesFor($utterances, false);

        // First voice heard takes the left; the other takes the right; each keeps its side.
        self::assertSame(ConversationSide::Left, $sides[0]);
        self::assertSame(ConversationSide::Right, $sides[1]);
        self::assertSame(ConversationSide::Left, $sides[2]);
    }

    public function testUnattributedSpeechSitsInTheMiddle(): void
    {
        $sides = ResponseTiming::sidesFor([
            $this->utterance(0, 500, SpeakerRole::UNKNOWN->value, SpeakerRole::UNKNOWN, 'inaudible'),
        ], true);

        self::assertSame(ConversationSide::Neutral, $sides[0]);
    }

    // ---------------------------------------------------------------- through the view

    /**
     * The whole thread as the page will render it, from the real shape of a stored transcript.
     *
     * Taken from `21911369.wav`: the 0.63 s and 0.71 s gaps are real pauses and get a number; the
     * 0.00 s boundaries are segmentation seams and get nothing.
     */
    public function testARealTranscriptShapeShowsOnlyTheGapsThatAreReal(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::COMPLETED, [
            $this->utterance(0, 770, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'me the same'),
            $this->utterance(1400, 2650, 'SPEAKER_01', SpeakerRole::AGENT, 'plate.'),
            $this->utterance(2650, 17_720, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Hi, can I place the order…'),
            $this->utterance(23_740, 32_910, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Anything else? Both spicy…'),
            $this->utterance(33_620, 41_090, 'SPEAKER_01', SpeakerRole::AGENT, 'One with less vegetables…'),
        ], 0.9);

        self::assertNull($view->turns[0]->timing->delayLabel(), 'first turn');
        self::assertSame('0.6s response', $view->turns[1]->timing->delayLabel(), '0.63s gap is real');
        self::assertNull($view->turns[2]->timing->delayLabel(), '0.00s gap is a seam');
        self::assertNull($view->turns[3]->timing->delayLabel(), 'same speaker continues');
        self::assertSame('0.7s response', $view->turns[4]->timing->delayLabel(), '0.71s gap is real');

        // And the sides still say what the labels say.
        self::assertSame(ConversationSide::Left, $view->turns[0]->side);
        self::assertSame(ConversationSide::Right, $view->turns[1]->side);
        self::assertSame('Customer', $view->turns[0]->label);
        self::assertSame('Agent', $view->turns[1]->label);
    }

    /** An unpublished split gets sides and timings, and still refuses to name a role. */
    public function testAnUnpublishedSplitKeepsNeutralLabelsWhileStillLayingOutTheThread(): void
    {
        $view = ConversationView::from(SpeakerSeparationStatus::NEEDS_REVIEW, [
            $this->utterance(0, 2000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'hello'),
            $this->utterance(3000, 4000, 'SPEAKER_01', SpeakerRole::AGENT, 'hi'),
        ], 0.08);

        self::assertFalse($view->rolesPublished);
        self::assertSame('Speaker 1', $view->turns[0]->label);
        self::assertSame('Speaker 2', $view->turns[1]->label);
        self::assertSame(ConversationSide::Left, $view->turns[0]->side);
        self::assertSame(ConversationSide::Right, $view->turns[1]->side);
        self::assertSame('1.0s response', $view->turns[1]->timing->delayLabel());
    }

    public function testTheThresholdIsStatedOnceAndUsed(): void
    {
        self::assertSame(150, TurnTiming::MIN_REPORTABLE_DELAY_MS);
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
