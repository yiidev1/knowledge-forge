<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Transcription;

use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\TranscriptionProvider;

/**
 * One speech-to-text engine.
 *
 * The port that makes the provider selectable. Modelled on {@see \App\AudioToText\Domain\Speaker\SpeakerDiarizerInterface}
 * — same layer, same shape, same reason: the thing that runs an external tool is named in Domain and
 * implemented in Infrastructure, so the application layer depends on the idea rather than the vendor.
 *
 * ## Readiness is local, and it is per engine
 *
 * `isAvailable()` and `assertReady()` inspect **local configuration only** — a path, a key, a setting.
 * Neither may open a socket. Deepgram's key is not verified by calling Deepgram: that would put a
 * network round trip inside an upload request, and a provider outage would then read as a
 * misconfiguration. The first real contact with Deepgram happens inside `recognise()`, in the worker.
 *
 * This is also what keeps the two engines independent. A missing whisper model makes
 * `WhisperEngine::assertReady()` throw and nothing else; Deepgram jobs on the same worker are
 * unaffected, and the reverse holds too. Neither can stop the worker starting, because worker startup
 * no longer validates either of them.
 */
interface TranscriptionEngineInterface
{
    /** Which provider this engine is, for storage and for display. */
    public function provider(): TranscriptionProvider;

    /**
     * Whether local configuration is complete enough to attempt a transcription.
     *
     * Cheap and side-effect free: used to warn at startup and to refuse a selection at upload time,
     * both of which happen often enough that neither may touch the network.
     */
    public function isAvailable(): bool;

    /**
     * The same judgement as {@see isAvailable()}, but saying why.
     *
     * Called once per job, **before any audio is converted**, so a job whose engine cannot run costs
     * nothing and fails with a sentence an administrator can act on.
     *
     * @throws AudioTranscriptionException when this engine is not configured to run
     */
    public function assertReady(): void;

    /**
     * Recognise speech in an already-normalised 16 kHz mono WAV.
     *
     * @throws AudioTranscriptionException on any failure — the job fails safely and the worker
     *                                     continues with the next one
     */
    public function recognise(TranscriptionRequest $request): AudioTranscriptionResult;
}
