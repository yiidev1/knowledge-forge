<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use function count;

/**
 * Whether this call's audio is in hand, in the words the recordings page uses.
 *
 * ## Why this is not {@see CallImportOutcome}
 *
 * That enum answers the same question for the calls page and must keep answering it exactly as it does
 * today, so this is a second reading of the same rows rather than a change to the first. The two differ
 * in one place, and it is the place that matters here:
 *
 * `CallImportOutcome` folds "the provider has no such recording" into **Failed**, which is the right
 * answer on a page whose subject is a transcription pipeline that now has nothing to transcribe. On a
 * page whose subject is the audio itself it is the wrong answer twice over — nothing failed, and there
 * is nothing to retry. A merchant who records one channel and not the other three produces a 404 on
 * CALLER and CALLEE every single time, and a column that called that a failure would be red on a healthy
 * day and so would be ignored by the second week.
 *
 * {@see Unavailable} is that case: settled, understood, and not anybody's fault.
 *
 * ## Partial is normal, not an edge
 *
 * Most merchants produce a mixed recording and nothing else. "Mixed downloaded, the two sides not
 * available" is what a healthy download of a typical call looks like, which is why it has its own word
 * instead of being reported as a shortfall against three.
 */
enum RecordingDownloadState: string
{
    /** No request has been made for this call. The only state from which downloading can be asked for. */
    case NotDownloaded = 'NOT_DOWNLOADED';

    /** At least one channel is still being fetched. Nothing final can be said yet. */
    case Downloading = 'DOWNLOADING';

    /** Every channel that was asked for is here. */
    case Downloaded = 'DOWNLOADED';

    /** Some audio arrived and some did not. The usual outcome for a single-channel merchant. */
    case Partial = 'PARTIAL';

    /** Settled, and the provider has none of it. Not a failure and not retryable. */
    case Unavailable = 'UNAVAILABLE';

    /** Something went wrong that might not go wrong again. */
    case Failed = 'FAILED';

    /**
     * One word for a whole call, from the channel rows it has.
     *
     * Null and the empty list are the same thing — a call nobody has asked for — because a call is not
     * stored anywhere until its channels are.
     *
     * @param list<Order58ImportStatus>|null $channels every channel row of one call
     */
    public static function fromChannels(?array $channels): self
    {
        if ($channels === null || $channels === []) {
            return self::NotDownloaded;
        }

        $here = 0;
        $moving = 0;
        $absent = 0;

        foreach ($channels as $status) {
            if (!$status->isSettled()) {
                ++$moving;

                continue;
            }

            if ($status === Order58ImportStatus::Imported) {
                ++$here;

                continue;
            }

            // Counted apart from the other shortfalls, because this is the one that is not a problem.
            // TooLarge and Failed both fall through to the generic branch below.
            if ($status === Order58ImportStatus::NotAvailable) {
                ++$absent;
            }
        }

        return match (true) {
            // Anything still moving outranks everything else: a final word about a call whose caller
            // channel is still downloading would be wrong before it was even printed.
            $moving > 0 => self::Downloading,
            $here === count($channels) => self::Downloaded,
            $here > 0 => self::Partial,
            // Nothing arrived. Whether that is a failure depends entirely on why.
            $absent === count($channels) => self::Unavailable,
            default => self::Failed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotDownloaded => 'Not downloaded',
            self::Downloading => 'Downloading',
            self::Downloaded => 'Downloaded',
            self::Partial => 'Partial',
            self::Unavailable => 'Unavailable',
            self::Failed => 'Failed',
        };
    }

    /** From the palette `admin.css` actually defines: `success|error|warning|info|muted`. */
    public function badge(): string
    {
        return match ($this) {
            self::Downloaded => 'success',
            self::Downloading => 'info',
            self::Partial => 'warning',
            self::Unavailable => 'muted',
            self::Failed => 'error',
            self::NotDownloaded => 'muted',
        };
    }

    /**
     * Whether asking for this call again could bring down audio that is not already here.
     *
     * False for {@see Unavailable}: the provider has answered, and asking a second time spends a
     * request to be told the same thing. False for {@see Downloading} because it is already happening,
     * and for {@see Downloaded} because there is nothing left to fetch.
     *
     * {@see Partial} is true — the sides that were not available will 404 again harmlessly, and a
     * channel that genuinely failed gets another go.
     */
    public function worthAsking(): bool
    {
        return $this === self::NotDownloaded || $this === self::Partial || $this === self::Failed;
    }
}
