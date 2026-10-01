<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * Where one recording channel has got to on its way in from the provider.
 *
 * ## Why this lives in the shared seam
 *
 * Two pages in two modules report the same five facts about the same row. The page that requested the
 * recordings shows them in a table; the store's audio page shows them in place of a player that does not
 * exist yet. Neither module may name the other, and a vocabulary defined twice is a vocabulary that
 * drifts — so the words live here, between them, and each side translates its own storage into these at
 * its boundary.
 *
 * ## Terminal and active, and why the distinction is load-bearing
 *
 * {@see isSettled()} splits these into "this channel is finished with" and "something is still going to
 * happen". Everything derived — the progress bar, whether to keep polling, whether a retry could help —
 * reads that one predicate rather than re-listing cases, so adding a state cannot leave one of them
 * quietly wrong.
 *
 * {@see Unavailable} is terminal and is **not** a failure. A merchant who records the mixed call and not
 * the two sides produces exactly this on every single call, which is why it is counted apart from
 * {@see Failed} wherever the two would otherwise be added together.
 */
enum RecordingAcquisitionState: string
{
    /**
     * Asked for, and the first provider fetch has not started.
     *
     * "Pending download" rather than "Waiting": waiting says nothing about what for, and a reader
     * scanning three channels needs to know at a glance which of them are still owed a file.
     */
    case Waiting = 'WAITING';

    /** Being fetched right now. */
    case Downloading = 'DOWNLOADING';

    /** Here, stored, and playable. */
    case Downloaded = 'DOWNLOADED';

    /** The provider has no such recording. Settled, expected, and not retryable. */
    case Unavailable = 'UNAVAILABLE';

    /** Something went wrong that might not go wrong again. */
    case Failed = 'FAILED';

    /** Nothing further will happen to this channel without somebody asking. */
    public function isSettled(): bool
    {
        return $this !== self::Waiting && $this !== self::Downloading;
    }

    /** Whether this channel's audio is in hand. The only state in which it can be played. */
    public function isAvailable(): bool
    {
        return $this === self::Downloaded;
    }

    /**
     * Whether asking again could bring down audio that is not already here.
     *
     * False for {@see Unavailable}: the provider has answered, and asking a second time spends a request
     * to be told the same thing. False for the two active states, because something is already happening.
     */
    public function worthRetrying(): bool
    {
        return $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Pending download',
            self::Downloading => 'Downloading',
            self::Downloaded => 'Downloaded',
            self::Unavailable => 'Unavailable',
            self::Failed => 'Failed',
        };
    }

    /**
     * This channel as a step in a progress list, in the words the transcription panel already uses.
     *
     * The same four values that component's stages take — `pending`, `active`, `complete`, `error` —
     * plus `skipped` for a channel the provider simply does not have. Reusing its vocabulary is what
     * makes a download and a transcription read as the same product rather than two takes on progress.
     *
     * {@see Unavailable} is deliberately not `complete` and not `error`: nothing arrived, and nothing
     * went wrong. A tick would claim a recording that is not there; a cross would blame somebody.
     */
    public function step(): string
    {
        return match ($this) {
            self::Waiting => 'pending',
            self::Downloading => 'active',
            self::Downloaded => 'complete',
            self::Unavailable => 'skipped',
            self::Failed => 'error',
        };
    }

    /** From the palette `admin.css` defines: `success|error|warning|info|muted`. */
    public function badge(): string
    {
        return match ($this) {
            self::Downloaded => 'success',
            self::Downloading => 'info',
            self::Waiting => 'muted',
            self::Unavailable => 'muted',
            self::Failed => 'error',
        };
    }
}
