<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Which of the store page's three upload cards a recording arrived through.
 *
 * ## Why this exists at all
 *
 * All three cards submit one mixed-format recording in `COMMON` mode, because that is what the
 * pipeline needs to know: the speakers still have to be discovered either way. That is correct for
 * transcription and useless for the history, where three visibly different cards all produced rows
 * reading "Common / Mixed". Which card was used was recorded nowhere, so it could not be shown.
 *
 * This records it. It is a **fact about the upload**, captured at the moment it is known, and it is
 * deliberately not derived afterwards from a filename — `22414839-caller.wav` is a name the operator's
 * telephony provider happened to give a file, not something this application may draw conclusions from.
 *
 * ## It is not a pipeline input
 *
 * Nothing downstream reads it. {@see ConversationMode} still decides whether diarization runs and
 * {@see SourceRole} still says what a file contains, exactly as before — a caller-only recording is
 * transcribed by the same code path, with the same provider, as it was before this existed. Keeping
 * those separate is the point: a display label must never be able to change what the worker does.
 *
 * ## Mixed says "Common / Mixed" on purpose
 *
 * That is what {@see ConversationMode::Common} already labels itself, and every upload made before
 * this column existed is a COMMON conversation that still reads exactly that way. A new mixed upload
 * and an old one are the same thing and say the same thing.
 *
 * Null — the absence of this — means "not recorded": every conversation uploaded before the cards were
 * told apart, and every separate Customer + Agent pair, which its mode describes instead.
 */
enum RecordingType: string
{
    case Mixed = 'MIXED';
    case Caller = 'CALLER';
    case Callee = 'CALLEE';

    public function label(): string
    {
        return match ($this) {
            self::Mixed => 'Common / Mixed',
            self::Caller => 'Caller',
            self::Callee => 'Callee',
        };
    }

    /**
     * The allow-list, applied to whatever the form posted.
     *
     * `tryFrom` rather than `from`: a value that is not one of the three is not an exception to handle,
     * it is simply not a recording type, and the upload records nothing rather than persisting a label
     * nobody can account for. **This is the only way a value reaches the column.**
     */
    public static function fromStorage(?string $value): ?self
    {
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
