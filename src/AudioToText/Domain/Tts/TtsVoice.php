<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use App\AudioToText\Domain\RecordingType;

/**
 * Which configured voice speaks a line.
 *
 * ## Why this is not {@see \App\AudioToText\Domain\SpeakerRole}
 *
 * A conversation picks its voices from the role, because the whole point of a mixed rendition is that a
 * listener can tell the two parties apart. A Caller recording has no two parties: it is one person, and
 * the diarizer's AGENT/CUSTOMER guesses inside it describe clusters rather than people. Selecting voices
 * from those guesses would build a two-hander out of one person's words — the single thing this must
 * never do.
 *
 * So a script says which voice speaks it, and only a conversation derives that from a role.
 *
 * ## Caller is not Customer
 *
 * Caller and Callee say who dialled. Customer and Agent say who works for the restaurant. Either party
 * can place a call, nothing in this application records which did, and the two vocabularies are kept
 * apart here for the same reason they are kept apart everywhere else in this feature.
 *
 * They therefore get **their own** settings. The defaults borrow the two voices already configured so
 * an existing deployment generates correctly the moment this ships — a convenience about which sound to
 * use, not a claim that a Caller is a Customer.
 */
enum TtsVoice: string
{
    case Customer = 'CUSTOMER';
    case Agent = 'AGENT';
    case Caller = 'CALLER';
    case Callee = 'CALLEE';

    /**
     * The voice a recording type names, or null where the recording holds a conversation.
     *
     * Mixed and absent both mean "both sides are in here", which is the case the role-driven script
     * exists for. Every row that predates recording types is in the second group.
     */
    public static function forRecording(?RecordingType $type): ?self
    {
        return match ($type) {
            RecordingType::Caller => self::Caller,
            RecordingType::Callee => self::Callee,
            RecordingType::Mixed, null => null,
        };
    }

    /** What an administrator is told is missing, when the voice is not configured. */
    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Agent => 'Agent',
            self::Caller => 'Caller',
            self::Callee => 'Callee',
        };
    }
}
