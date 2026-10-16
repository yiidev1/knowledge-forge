<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Playback;

use App\OrderTesting\Domain\Playback\GapSelection;
use App\OrderTesting\Domain\Playback\NewPlaybackSession;
use App\OrderTesting\Domain\Playback\PlaybackEventType;
use App\OrderTesting\Domain\Playback\PlaybackRepositoryInterface;
use App\OrderTesting\Domain\Playback\PlaybackSession;
use App\OrderTesting\Domain\Playback\PlaybackStatus;
use App\Shared\Audio\PlaybackGapOptions;

use function max;
use function min;
use function preg_match;
use function trim;

/**
 * What the browser reports about a reading, turned into rows that can be counted.
 *
 * ## Everything identifying comes from the caller's session, not from the request
 *
 * The agent id, the store, the order and the recording are all passed in by the action after its
 * middleware has proved them. Nothing in the posted body names a person or a recording, so no body can
 * claim either. What the body *does* say is what happened — started, paused, finished — and that is
 * treated as a report rather than as a fact: a transition the rules refuse changes nothing.
 *
 * ## What this will not do
 *
 * It will not invent a completion. A session whose browser disappeared stays as it was, started and
 * unfinished, and the reporting says so. Nothing here reaches a provider, writes to a transcript, or
 * touches a recording.
 */
final readonly class PlaybackTracker
{
    public function __construct(private PlaybackRepositoryInterface $sessions) {}

    /**
     * A dropdown change with no attempt behind it.
     *
     * The gap is validated against the one list before it is stored, so a hand-written request cannot
     * record a selection of a value the page never offered.
     */
    public function recordSelection(PlaybackContext $context, mixed $gap): bool
    {
        $gapMs = PlaybackGapOptions::normalize($gap);

        if ($gapMs === null) {
            return false;
        }

        $this->sessions->recordSelection(new GapSelection(
            agentAdminId: $context->agentAdminId,
            agentDisplayName: $context->agentDisplayName,
            storeSourceId: $context->storeSourceId,
            sourceOrderId: $context->sourceOrderId,
            jobPublicId: $context->jobPublicId,
            recordingLabel: $context->recordingLabel,
            gapMs: $gapMs,
        ));

        return true;
    }

    /**
     * Open an attempt, or return the one this token already opened.
     *
     * The gap is snapshotted here and never read again for this session: changing the dropdown while a
     * reading is in flight records a selection and applies to the next attempt, which is what keeps a
     * recorded session honest about the pause it actually used.
     */
    public function request(PlaybackContext $context, string $token, mixed $gap, int $messageTotal): ?PlaybackSession
    {
        $gapMs = PlaybackGapOptions::normalize($gap);

        if ($gapMs === null || !$this->isToken($token)) {
            return null;
        }

        return $this->sessions->openSession(new NewPlaybackSession(
            publicId: $token,
            agentAdminId: $context->agentAdminId,
            agentDisplayName: $context->agentDisplayName,
            storeSourceId: $context->storeSourceId,
            sourceOrderId: $context->sourceOrderId,
            jobPublicId: $context->jobPublicId,
            recordingLabel: $context->recordingLabel,
            gapMs: $gapMs,
            // Bounded, because it arrives from the browser. A conversation of 30,000 messages is a
            // malformed report, not a conversation.
            messageTotal: min(max(0, $messageTotal), 9999),
        ));
    }

    /**
     * Move an attempt on.
     *
     * Ownership is checked here and not left to the query: a session belongs to the agent who opened
     * it, and an agent holding somebody else's token must not be able to advance their session. The
     * status rules in the repository then decide whether the transition is one that may happen at all.
     */
    public function advance(
        PlaybackContext $context,
        string $token,
        PlaybackEventType $event,
        ?int $messagePlayed,
        ?string $detail,
    ): ?PlaybackSession {
        if (!$this->isToken($token) || $event === PlaybackEventType::GapSelected) {
            return null;
        }

        $session = $this->sessions->findSession($token);

        if ($session === null
            || $session->agentAdminId !== $context->agentAdminId
            || $session->jobPublicId !== $context->jobPublicId) {
            return null;
        }

        return $this->sessions->advance(
            $session,
            $event,
            $this->statusFor($event),
            $messagePlayed,
            $detail === null ? null : (trim($detail) ?: null),
        );
    }

    /**
     * Which status an event leaves the session in, and which leave it alone.
     *
     * Pause and Resume are deliberately absent: holding a reading does not change whether it is
     * playing, and recording them as a status change would make a paused session look finished to
     * every count that reads the status. They are kept as events, where they belong.
     */
    private function statusFor(PlaybackEventType $event): ?PlaybackStatus
    {
        return match ($event) {
            PlaybackEventType::PlayStarted => PlaybackStatus::Playing,
            PlaybackEventType::Completed => PlaybackStatus::Completed,
            PlaybackEventType::Stopped => PlaybackStatus::Stopped,
            PlaybackEventType::PlaybackError => PlaybackStatus::Error,
            default => null,
        };
    }

    /** The browser's token: 32 hex characters, the same shape every public id here uses. */
    private function isToken(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $token) === 1;
    }
}
