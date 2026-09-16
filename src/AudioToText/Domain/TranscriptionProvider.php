<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Which engine turns a recording into text.
 *
 * Chosen once, at upload, and written onto the job — never re-read from the global default when the
 * worker eventually runs. A recording queued while the default was Whisper is transcribed by Whisper
 * however many times the default changes in the meantime, which is the only behaviour that makes the
 * stored `transcription_provider` a record rather than a guess.
 *
 * `NULL` in the database means Whisper: every job that predates provider selection was produced by
 * whisper.cpp, and back-filling a column to say so would rewrite history to record something that was
 * never a decision. {@see TranscriptionJob::transcriptionProvider()} is the one place that resolution
 * lives.
 */
enum TranscriptionProvider: string
{
    /** Local whisper.cpp. The default, and the behaviour every existing job was produced by. */
    case Whisper = 'WHISPER';

    /** Deepgram's hosted speech-to-text. Transcription only — diarization stays local. */
    case Deepgram = 'DEEPGRAM';

    public function label(): string
    {
        return match ($this) {
            self::Whisper => 'Whisper (local)',
            self::Deepgram => 'Deepgram (cloud)',
        };
    }

    /** The short name for a table cell, where the parenthetical would only add noise. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Whisper => 'Whisper',
            self::Deepgram => 'Deepgram',
        };
    }

    /**
     * Decode a stored or posted value.
     *
     * Returns null rather than defaulting, so a caller has to decide what an unrecognised value means:
     * a stored NULL is legacy Whisper, but a *posted* unknown is a rejected form. Collapsing those two
     * into one silent default is how an invalid selection becomes an accepted one.
     */
    public static function fromStorage(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Whisper, self::Deepgram];
    }
}
