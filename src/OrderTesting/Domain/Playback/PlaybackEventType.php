<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/**
 * What happened during, or instead of, one attempt to hear a conversation read aloud.
 *
 * ## Two kinds of event, and the difference is the whole point
 *
 * {@see GapSelected} belongs to nobody's playback: it is a person changing the dropdown, which they
 * may do three times and then walk away. Every other case belongs to a session — a real attempt that
 * was requested and may have started.
 *
 * Keeping them in one table and one vocabulary is deliberate; counting them as one thing is the error
 * this enum exists to prevent. {@see belongsToSession()} is where that line is drawn.
 */
enum PlaybackEventType: string
{
    /** The dropdown changed. No session, and never a play. */
    case GapSelected = 'GAP_SELECTED';

    /** Play was pressed and the server accepted the attempt. */
    case PlayRequested = 'PLAY_REQUESTED';

    /** The speech engine reported the first utterance actually beginning. */
    case PlayStarted = 'PLAY_STARTED';

    case Paused = 'PAUSED';
    case Resumed = 'RESUMED';

    /** The reader ended it early, or the dialog closed under it. */
    case Stopped = 'STOPPED';

    /** The last message was read to its end. */
    case Completed = 'COMPLETED';

    /** The engine refused or faulted. May arrive before or after {@see PlayStarted}. */
    case PlaybackError = 'PLAYBACK_ERROR';

    /** Every event except a bare selection hangs off a session. */
    public function belongsToSession(): bool
    {
        return $this !== self::GapSelected;
    }

    /** Whether this event ends the session it belongs to. */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Stopped || $this === self::PlaybackError;
    }

    public function label(): string
    {
        return match ($this) {
            self::GapSelected => 'Gap selected',
            self::PlayRequested => 'Play requested',
            self::PlayStarted => 'Playback started',
            self::Paused => 'Paused',
            self::Resumed => 'Resumed',
            self::Stopped => 'Stopped',
            self::Completed => 'Completed',
            self::PlaybackError => 'Playback error',
        };
    }

    /** @return list<string> every value, for the migration's CHECK and for validation */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
