<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/**
 * Where one playback attempt got to.
 *
 * ## Why the status is not how a play is counted
 *
 * A session can fail before the engine ever speaks — no voices installed, a refused `speak()` — and it
 * ends as {@see Error} having played nothing. Counting "not Requested" as a play would count that one.
 * So the status says how the attempt *ended*, and `started_at` says whether it ever *began*; the
 * reporting counts the second. See {@see PlaybackSession::hasStarted()}.
 */
enum PlaybackStatus: string
{
    /** Play was pressed; the engine has not reported speaking yet. */
    case Requested = 'REQUESTED';

    /** The engine is speaking, or holding between two messages. */
    case Playing = 'PLAYING';

    /** Every message was read to its end. */
    case Completed = 'COMPLETED';

    /** Ended early — Stop, a closed dialog, a switched recording. */
    case Stopped = 'STOPPED';

    /** The engine refused or faulted. */
    case Error = 'ERROR';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Stopped || $this === self::Error;
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Not started',
            self::Playing => 'In progress',
            self::Completed => 'Completed',
            self::Stopped => 'Stopped',
            self::Error => 'Error',
        };
    }

    /**
     * Whether this status may follow that one.
     *
     * A finished session is finished: a late `COMPLETED` from a tab that was left open must not
     * resurrect a session the reader stopped ten minutes ago, and a second `COMPLETED` must not be
     * able to move anything at all.
     */
    public function mayFollow(self $current): bool
    {
        if ($current->isFinal()) {
            return false;
        }

        return match ($this) {
            self::Requested => false,
            self::Playing => $current === self::Requested,
            default => true,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
