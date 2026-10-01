<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * What a batch of imported recordings was asked for.
 *
 * ## Two requests that used to be one
 *
 * Importing a recording and transcribing it have always happened together, because the only way to hand
 * a file to the audio pipeline was to ask it for a transcript. That is right when the point is the text.
 * It is wrong when somebody wants to hear a call before deciding whether a transcript is worth paying
 * for — and until now every imported recording cost CPU or money whether or not anybody wanted it.
 *
 * This is the fork, read once by the import worker when it has a file in hand.
 *
 * ## Why a mode and not a flag
 *
 * `transcribe = false` reads as a missing step. These are two different kinds of request and a row should
 * say which it is in its own words — the same reason `triggered_by` beside it spells MANUAL and
 * AUTOMATIC rather than storing a boolean.
 */
enum CallImportMode: string
{
    /**
     * Download the recording and stop.
     *
     * The file is stored, becomes playable, and nothing is sent to a speech provider. A person asks for
     * the text later, one recording at a time, or never.
     */
    case DownloadOnly = 'DOWNLOAD_ONLY';

    /**
     * Download the recording and ask for its transcript, as imports have always done.
     *
     * The default for every existing row and for every batch the calls page creates, which is what makes
     * the other case safe to add.
     */
    case DownloadAndTranscribe = 'DOWNLOAD_AND_TRANSCRIBE';

    /** Whether the pipeline should be asked for a transcript once the file is stored. */
    public function transcribes(): bool
    {
        return $this === self::DownloadAndTranscribe;
    }

    /**
     * An unrecognised stored value reads as the behaviour imports have always had.
     *
     * Fail towards the old path rather than the new one: a row whose mode cannot be read is far more
     * likely to predate this column than to be a download somebody meant to leave untranscribed.
     */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DownloadAndTranscribe;
    }
}
