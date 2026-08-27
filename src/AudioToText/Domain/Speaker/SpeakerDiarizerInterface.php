<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * Splits an audio file into timestamped neutral speaker turns.
 *
 * The interface exists so the null implementation is a first-class citizen rather than a flag check
 * scattered through the worker: with diarization disabled the pipeline still runs end to end and simply
 * reports NOT_SUPPORTED.
 */
interface SpeakerDiarizerInterface
{
    public function isAvailable(): bool;

    /** A short identifier stored in `speaker_separation_method` for audit. */
    public function method(): string;

    /**
     * @param string $wavPath 16 kHz mono PCM WAV — the same file whisper.cpp transcribed, so the two
     *                        timelines share an origin and alignment needs no offset correction
     *
     * @return list<SpeakerSegment> chronological
     *
     * @throws \App\AudioToText\Domain\AudioTranscriptionException on any failure; the caller downgrades
     *                                                            it to a separation status rather than
     *                                                            failing the transcription
     */
    public function diarize(string $wavPath): array;
}
