<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use App\Shared\Audio\RecordingTypeLabels;

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
 * ## Mixed says "Mix / Common" on purpose
 *
 * That is what the store page's own column has always been headed, and a mixed upload should not be
 * called one thing in a table and another inside a dialog. It also keeps the tie to
 * {@see ConversationMode::Common}, which is what every upload made before this column existed is.
 *
 * Null — the absence of this — means "not recorded": every conversation uploaded before the cards were
 * told apart, and every separate Customer + Agent pair, which its mode describes instead.
 */
enum RecordingType: string
{
    case Mixed = 'MIXED';
    case Caller = 'CALLER';
    case Callee = 'CALLEE';

    /**
     * What this is called on screen — Mix / Common, Customer, Agent.
     *
     * Delegated to {@see RecordingTypeLabels}, which is in `Shared` because Order58's store cards show
     * the same three counts and may not name this module. The words are therefore written down once for
     * the whole application, and changing what a client calls these is one edit in one file.
     *
     * **The display names are not the stored values.** CALLER reads "Customer" and CALLEE reads "Agent"
     * because that is the vocabulary the client's operators use; the cases, the column and every query
     * still say CALLER and CALLEE. Those two words also belong to {@see SourceRole}, where they mean
     * something else — who works for the restaurant, rather than who dialled — so a legacy separate pair
     * and a caller recording now read alike. That was accepted deliberately; see `RecordingTypeLabels`.
     * Nothing in the code collides, and nothing maps one onto the other.
     *
     * The fallback can only be reached by a case being added above without a label beside it, which the
     * enum's own test refuses.
     */
    public function label(): string
    {
        return RecordingTypeLabels::forStorageValue($this->value) ?? $this->value;
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
