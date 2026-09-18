<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * Where one rendition's latest generation attempt got to.
 *
 * Deliberately describes the **attempt**, not the file. A row can be FAILED and still have perfectly
 * good audio on disk from an earlier run — that is the whole point of keeping a regeneration's failure
 * from destroying what it was replacing — so "can this be played?" is answered by the presence of a
 * file, never by this enum. {@see TtsRendition::isPlayable()}.
 */
enum TtsStatus: string
{
    /** Waiting for the TTS worker. Nothing has been spent. */
    case Queued = 'QUEUED';

    /** Claimed by a worker. Money may already be committed, so nothing may re-enqueue it. */
    case Generating = 'GENERATING';

    case Ready = 'READY';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether an attempt is outstanding.
     *
     * The guard against paying twice: a rendition in either of these states must never be re-enqueued,
     * however the request arrives — a second click, a refresh, the automatic trigger firing alongside a
     * manual one, or role confirmation landing while the worker is already mid-flight.
     */
    public function isInFlight(): bool
    {
        return $this === self::Queued || $this === self::Generating;
    }

    public function isTerminal(): bool
    {
        return !$this->isInFlight();
    }

    /** The `a2t-badge--*` modifier for this state. */
    public function badgeModifier(): string
    {
        return match ($this) {
            self::Queued => 'queued',
            self::Generating => 'processing',
            self::Ready => 'completed',
            self::Failed => 'failed',
        };
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
