<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * What one physical recording contains.
 *
 * `Common` is a mixed recording holding both speakers — the role of the *file*, not of any turn
 * inside it. `Customer` and `Agent` are recordings the administrator identified when uploading, which
 * is why a job carrying one of them never needs diarization or role mapping.
 *
 * Deliberately distinct from {@see SpeakerRole}: that names who is speaking within a transcript, and
 * is inferred. This names what an administrator said a file is, and is given.
 */
enum SourceRole: string
{
    case Common = 'COMMON';
    case Customer = 'CUSTOMER';
    case Agent = 'AGENT';

    public function label(): string
    {
        return match ($this) {
            self::Common => 'Common',
            self::Customer => 'Customer',
            self::Agent => 'Agent',
        };
    }

    /** Whether this file's speakers are known already, rather than something to work out. */
    public function isProvided(): bool
    {
        return $this !== self::Common;
    }

    /** The speaker role this file's whole transcript belongs to, or null for a mixed recording. */
    public function speakerRole(): ?SpeakerRole
    {
        return match ($this) {
            self::Common => null,
            self::Customer => SpeakerRole::CUSTOMER,
            self::Agent => SpeakerRole::AGENT,
        };
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
