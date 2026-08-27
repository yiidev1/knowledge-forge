<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The business role a diarized speaker cluster was mapped to.
 *
 * Roles are assigned only after acoustic clustering has produced neutral speakers; the neutral label is
 * kept alongside the role in the stored segments so a mapping can always be audited. `OTHER` holds a
 * third voice — background television, a colleague, an automated greeting — and `UNKNOWN` holds speech
 * that could not be attributed at all. Neither is ever folded into the agent or customer columns.
 */
enum SpeakerRole: string
{
    case AGENT = 'AGENT';
    case CUSTOMER = 'CUSTOMER';
    case OTHER = 'OTHER';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::AGENT => 'Agent',
            self::CUSTOMER => 'Customer',
            self::OTHER => 'Other speaker',
            self::UNKNOWN => 'Unattributed',
        };
    }

    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::UNKNOWN;
    }
}
