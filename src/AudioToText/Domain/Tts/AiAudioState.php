<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * What the AI Audio page says about one output, as one word.
 *
 * Wider than {@see TtsStatus} on purpose. That enum describes the last *attempt*; this describes the
 * whole situation, which is what a reader actually needs — and the two differ in the cases that matter
 * most:
 *
 *  - **Stale** is a READY rendition whose transcript has since been corrected. The audio is fine, it is
 *    just no longer a reading of what the transcript says.
 *  - **Failed** may still have playable audio behind it, because a regeneration that fails leaves the
 *    file it was replacing exactly where it was.
 *  - **Blocked** and **Unavailable** are not attempts at all. Nothing has been tried and nothing is
 *    wrong; the recording is simply not in a state where a voice could honestly be assigned.
 */
enum AiAudioState
{
    /** Nothing has been asked for. */
    case NotGenerated;

    case Queued;
    case Generating;

    /** Current audio for the current transcript and the current voices. */
    case Ready;

    /** Playable, but the transcript has changed since. Never regenerated automatically — that costs money. */
    case Stale;

    /** The words are still right; a voice or format setting has changed since. An offer, not a warning. */
    case DifferentVoice;

    case Failed;

    /** The speakers are not published, so no voice may be put to them yet. */
    case Blocked;

    /** This recording cannot produce this output at all. */
    case Unavailable;

    public function label(): string
    {
        return match ($this) {
            self::NotGenerated => 'Not generated',
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Ready => 'Ready',
            self::Stale => 'Stale',
            self::DifferentVoice => 'Ready',
            self::Failed => 'Failed',
            self::Blocked => 'Speakers not confirmed',
            self::Unavailable => 'Not available',
        };
    }

    /** The `a2t-badge--*` modifier, reusing the badge styles the rest of the module already uses. */
    public function badgeModifier(): string
    {
        return match ($this) {
            self::Queued => 'queued',
            self::Generating => 'processing',
            self::Ready, self::DifferentVoice => 'completed',
            self::Stale => 'stale',
            self::Failed => 'failed',
            self::NotGenerated, self::Blocked, self::Unavailable => 'idle',
        };
    }

    /** Whether the worker is expected to act on this without anybody doing anything. */
    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Generating;
    }
}
