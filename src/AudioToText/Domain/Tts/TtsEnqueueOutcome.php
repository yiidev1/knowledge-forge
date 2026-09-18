<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * What happened when a generation was asked for.
 *
 * Four outcomes rather than a boolean, because three of them are not failures and each deserves
 * different words. "Nothing happened" covers a duplicate click, a refresh, audio that is already current
 * and a worker that is already running — and telling an administrator "already queued" when the truth is
 * "already up to date" teaches them the page is guessing.
 *
 * Five separate triggers can ask for a generation: the upload's own request honoured at transcription,
 * the same request honoured later at speaker confirmation, a Generate click, a Regenerate click, and a
 * retry. All five go through one method and get one of these back.
 */
enum TtsEnqueueOutcome
{
    /** A paid attempt is now authorised. The only outcome that will cost anything. */
    case Queued;

    /** An attempt is already outstanding. Nothing was queued and nothing will be billed twice. */
    case AlreadyRunning;

    /** Audio for this exact transcript and these exact voices already exists. */
    case AlreadyCurrent;

    /** There is no such rendition to act on — a stale form, or a job that has since been removed. */
    case NotFound;

    public function queued(): bool
    {
        return $this === self::Queued;
    }

    /**
     * A sentence for the administrator who pressed the button.
     *
     * Kept next to the outcome so the page cannot drift from the decision: if a fifth outcome is ever
     * added, this match stops compiling rather than quietly falling through to "queued".
     */
    public function message(TtsOutputType $type): string
    {
        return match ($this) {
            self::Queued => $type->label() . ' has been queued. It will be generated shortly.',
            self::AlreadyRunning => $type->label() . ' is already being generated. Nothing was queued twice.',
            self::AlreadyCurrent => $type->label() . ' is already up to date for the current transcript.',
            self::NotFound => 'That recording is no longer available.',
        };
    }
}
