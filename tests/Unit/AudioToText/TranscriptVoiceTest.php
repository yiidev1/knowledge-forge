<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\ConversationSide;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use Codeception\Test\Unit;

use function array_map;

/**
 * A recording whose speaker was named at upload time is one person's words.
 *
 * The case worth pinning is the awkward one: a Caller upload is transcribed exactly like a mixed
 * recording, so the diarizer runs and really does return two clusters with AGENT and CUSTOMER
 * hypotheses attached. Those rows are real and are never rewritten. What must not happen is the
 * screens reading them as two people, because somebody already said there is only one.
 */
final class TranscriptVoiceTest extends Unit
{
    /** Mixed and absent both mean "both sides are in here", which is what diarization is for. */
    public function testOnlyCallerAndCalleeNameASpeaker(): void
    {
        self::assertNull(TranscriptVoice::forRecording(RecordingType::Mixed));
        self::assertNull(TranscriptVoice::forRecording(null));

        $caller = TranscriptVoice::forRecording(RecordingType::Caller);
        $callee = TranscriptVoice::forRecording(RecordingType::Callee);

        self::assertNotNull($caller);
        self::assertNotNull($callee);
        self::assertSame('Caller', $caller->label);
        self::assertSame('Callee', $callee->label);
        // Opposite sides, so the two kinds of recording are told apart at a glance.
        self::assertSame(ConversationSide::Left, $caller->side);
        self::assertSame(ConversationSide::Right, $callee->side);
    }

    /**
     * The diarizer found two speakers. It is still one person.
     *
     * Every input here is what a real Caller recording carries — two clusters, both with role
     * hypotheses, a publishable status — and none of it may reach the reader.
     */
    public function testANamedRecordingReadsAsOnePersonHoweverManySpeakersWereFound(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::COMPLETED,
            $this->twoClusters(),
            0.97,
            true,
            true,
            TranscriptVoice::forRecording(RecordingType::Caller),
        );

        self::assertCount(3, $view->turns);

        foreach ($view->turns as $turn) {
            self::assertSame('Caller', $turn->label);
            self::assertSame(ConversationSide::Left, $turn->side);
            // Nothing is provisional: the fact came from a person, not from a threshold.
            self::assertTrue($turn->confirmed);
        }

        self::assertTrue($view->rolesPublished, 'There is nothing left to establish.');
        self::assertSame([], $view->hypotheses, 'A guess about which cluster is the agent is not a question here.');
    }

    /** Callee likewise, on its own side. */
    public function testACalleeRecordingReadsAsCallee(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            $this->twoClusters(),
            0.2,
            false,
            false,
            TranscriptVoice::forRecording(RecordingType::Callee),
        );

        foreach ($view->turns as $turn) {
            self::assertSame('Callee', $turn->label);
            self::assertSame(ConversationSide::Right, $turn->side);
        }

        // Even from an unpublishable separation: the recording type is the stronger fact, and the
        // question the status answers is one this recording does not pose.
        self::assertTrue($view->rolesPublished);
    }

    /**
     * Without a named voice, nothing moves.
     *
     * The same utterances through the same call, with the argument absent, must behave exactly as they
     * did before this concept existed — that is what keeps every mixed recording untouched.
     */
    public function testAConversationIsUnaffected(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::COMPLETED,
            $this->twoClusters(),
            0.97,
            true,
            true,
        );

        self::assertSame(['Customer', 'Agent', 'Customer'], array_map(
            static fn($turn): string => $turn->label,
            $view->turns,
        ));
        self::assertTrue($view->rolesPublished);
    }

    /** And an unpublished conversation still withholds the roles it could not establish. */
    public function testAnUnpublishedConversationStillShowsNeutralSpeakers(): void
    {
        $view = ConversationView::from(
            SpeakerSeparationStatus::NEEDS_REVIEW,
            $this->twoClusters(),
            0.2,
            false,
            false,
        );

        self::assertSame(['Speaker 1', 'Speaker 2', 'Speaker 1'], array_map(
            static fn($turn): string => $turn->label,
            $view->turns,
        ));
        self::assertFalse($view->rolesPublished);
    }

    /**
     * Two clusters with role hypotheses on every utterance — what a diarized recording actually holds.
     *
     * @return list<SpeakerUtterance>
     */
    private function twoClusters(): array
    {
        return [
            new SpeakerUtterance(0, 3000, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Hi, can I get a large pepperoni?', 0.95),
            new SpeakerUtterance(3500, 5500, 'SPEAKER_01', SpeakerRole::AGENT, 'Pickup or delivery?', 0.95),
            new SpeakerUtterance(6000, 7200, 'SPEAKER_00', SpeakerRole::CUSTOMER, 'Pickup please.', 0.95),
        ];
    }
}
