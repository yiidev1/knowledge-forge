<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\AudioToText\Domain\ConversationCapabilities;
use App\OrderTesting\Domain\Playback\PlaybackEventType;
use App\OrderTesting\Domain\Playback\PlaybackSession;
use App\OrderTesting\Domain\Playback\PlaybackStatus;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide what counts as a play, and which reports are allowed to change anything.
 *
 * These are the counting rules themselves rather than the SQL that applies them: if a stop could be
 * recorded as a completion, or a failure before the first word could count as listening, it would be
 * decided here.
 */
final class PlaybackLifecycleTest extends TestCase
{
    private function session(PlaybackStatus $status, ?string $startedAt): PlaybackSession
    {
        return new PlaybackSession(
            id: 1,
            publicId: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            agentAdminId: 473,
            agentDisplayName: 'Agent',
            storeSourceId: 1731,
            sourceOrderId: 16800401,
            jobPublicId: 'f0e1d2c3b4a5968778695a4b3c2d1e0f',
            recordingLabel: 'MIXED',
            gapMs: 300,
            messageTotal: 16,
            messagePlayed: 0,
            status: $status,
            requestedAt: '2026-10-16 10:00:00',
            startedAt: $startedAt,
            finishedAt: null,
        );
    }

    /**
     * The correction that matters most: a play is a start, not a status.
     *
     * A session can fail before the engine says a word and end as ERROR. Counting "not REQUESTED"
     * would count that as a play; counting `started_at` does not — and a session that started and was
     * then stopped or failed still started, because it did.
     */
    public function testAPlayIsCountedByItsStartAndNotByHowItEnded(): void
    {
        self::assertFalse($this->session(PlaybackStatus::Requested, null)->hasStarted());
        self::assertFalse($this->session(PlaybackStatus::Error, null)->hasStarted());

        self::assertTrue($this->session(PlaybackStatus::Playing, '2026-10-16 10:00:01')->hasStarted());
        self::assertTrue($this->session(PlaybackStatus::Completed, '2026-10-16 10:00:01')->hasStarted());
        self::assertTrue($this->session(PlaybackStatus::Stopped, '2026-10-16 10:00:01')->hasStarted());
        self::assertTrue($this->session(PlaybackStatus::Error, '2026-10-16 10:00:01')->hasStarted());
    }

    /** A finished session is finished. A late report cannot revive it or change what it says. */
    public function testAFinishedSessionAcceptsNothingFurther(): void
    {
        foreach ([PlaybackStatus::Completed, PlaybackStatus::Stopped, PlaybackStatus::Error] as $final) {
            self::assertTrue($final->isFinal());

            foreach (PlaybackStatus::cases() as $next) {
                self::assertFalse(
                    $next->mayFollow($final),
                    $next->value . ' must not follow ' . $final->value,
                );
            }
        }
    }

    public function testPlayingOnlyFollowsARequestAndNothingReturnsToRequested(): void
    {
        self::assertTrue(PlaybackStatus::Playing->mayFollow(PlaybackStatus::Requested));
        self::assertFalse(PlaybackStatus::Playing->mayFollow(PlaybackStatus::Playing));
        self::assertFalse(PlaybackStatus::Requested->mayFollow(PlaybackStatus::Requested));
        self::assertFalse(PlaybackStatus::Requested->mayFollow(PlaybackStatus::Playing));
    }

    public function testAnAttemptMayEndFromEitherLiveState(): void
    {
        foreach ([PlaybackStatus::Requested, PlaybackStatus::Playing] as $live) {
            self::assertTrue(PlaybackStatus::Completed->mayFollow($live));
            self::assertTrue(PlaybackStatus::Stopped->mayFollow($live));
            self::assertTrue(PlaybackStatus::Error->mayFollow($live));
        }
    }

    /** A dropdown change is the one event with no attempt behind it, and it is never terminal. */
    public function testOnlyASelectionBelongsToNoSession(): void
    {
        self::assertFalse(PlaybackEventType::GapSelected->belongsToSession());
        self::assertFalse(PlaybackEventType::GapSelected->isTerminal());

        foreach (PlaybackEventType::cases() as $event) {
            if ($event !== PlaybackEventType::GapSelected) {
                self::assertTrue($event->belongsToSession(), $event->value . ' needs a session');
            }
        }
    }

    public function testTheThreeEndingsAreTerminalAndTheRestAreNot(): void
    {
        self::assertTrue(PlaybackEventType::Completed->isTerminal());
        self::assertTrue(PlaybackEventType::Stopped->isTerminal());
        self::assertTrue(PlaybackEventType::PlaybackError->isTerminal());

        foreach ([
            PlaybackEventType::PlayRequested,
            PlaybackEventType::PlayStarted,
            PlaybackEventType::Paused,
            PlaybackEventType::Resumed,
        ] as $event) {
            self::assertFalse($event->isTerminal(), $event->value . ' does not end a session');
        }
    }

    /**
     * Tracking is not a relaxation of read-only.
     *
     * The agent profile answers no to every way of changing a recording — exactly as `readOnly()`
     * does — and yes only to recording what it played. And `readOnly()` itself is untouched, which the
     * administrators' own practice page still depends on.
     */
    public function testTheAgentProfileAddsTrackingAndNothingElse(): void
    {
        $agent = ConversationCapabilities::agentPractice();
        $readOnly = ConversationCapabilities::readOnly();

        foreach ([
            'canUploadAudio', 'canReplaceAudio', 'canTranscribe', 'canEditTranscript',
            'canGenerateTts', 'canOpenFullEditor', 'canSyncDemoOrders', 'canAssignTopics',
            'canVerifyOrders', 'canViewAgentEvents',
        ] as $forbidden) {
            self::assertFalse($agent->{$forbidden}, 'agents must not have ' . $forbidden);
            self::assertFalse($readOnly->{$forbidden}, 'read-only must not have ' . $forbidden);
        }

        self::assertTrue($agent->canTrackPlayback);
        self::assertFalse($readOnly->canTrackPlayback);

        // The two realms that manage orders can read the reports; nobody who is read-only can.
        self::assertTrue(ConversationCapabilities::full()->canViewAgentEvents);
        self::assertFalse(ConversationCapabilities::full()->canTrackPlayback);
    }
}
