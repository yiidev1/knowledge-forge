<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * How the recordings for one conversation were supplied.
 *
 * This is the feature's **provenance flag**, and it is why no separate `role_source` column exists.
 * `Common` means one mixed recording whose speakers the pipeline must work out for itself; `Separate`
 * means the administrator handed us the Customer and the Agent as distinct files, so their roles are
 * a fact rather than an inference.
 *
 * Everything downstream reads that distinction from here: whether diarization runs, whether role
 * confirmation is offered, and whether the turn-based conversation and correction screens apply at all.
 */
enum ConversationMode: string
{
    case Common = 'COMMON';
    case Separate = 'SEPARATE';

    public function label(): string
    {
        return match ($this) {
            self::Common => 'Common / Mixed',
            self::Separate => 'Separate Customer + Agent',
        };
    }

    /**
     * Whether the speakers still have to be discovered.
     *
     * False for a separate upload: the roles were supplied, so running the diarizer would spend a
     * minute of CPU rediscovering something we were told.
     */
    public function needsSpeakerSeparation(): bool
    {
        return $this === self::Common;
    }

    /**
     * The roles each child of this mode carries, in the order they are created.
     *
     * @return non-empty-list<SourceRole>
     */
    public function childRoles(): array
    {
        return match ($this) {
            self::Common => [SourceRole::Common],
            self::Separate => [SourceRole::Customer, SourceRole::Agent],
        };
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
