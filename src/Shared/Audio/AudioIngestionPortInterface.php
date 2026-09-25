<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * Offer a recording that is already on disk to the transcription pipeline.
 *
 * ## Why this interface exists in `Shared` rather than in either module
 *
 * `ModuleIsolationTest` enforces both directions: the transcription module may not name Order58, and
 * **no other directory under `src/` may name the transcription module** — a rule so literal that this
 * very sentence may not spell its namespace out. A recording importer needs both sides: the external
 * call the audio came from, and the pipeline it is going into. It can therefore live in neither module.
 *
 * This is the seam. The transcription module implements it, Order58 depends on it, and neither names the
 * other — both are only naming `App\Shared`, which is allowed. It is the same trick
 * `AudioProviderDefaultInterface` plays with a table name, expressed as a call instead of a query.
 *
 * ## Strings, not enums
 *
 * `$recordingType` and `$provider` are the storage values — `MIXED`, `WHISPER` — rather than the enums
 * that describe them, because those enums belong to Audio-to-Text and a caller in another module may not
 * name them. The implementation maps them and refuses a value it does not recognise, so the looseness
 * costs nothing: an unknown string is a rejection, not a default.
 *
 * ## A path, not an upload
 *
 * The caller has a file it downloaded; wrapping it as a PSR-7 upload is the pipeline's business, not the
 * caller's. Passing the path keeps the storage rules — where bytes may live, what they may be named — on
 * one side of the seam.
 */
interface AudioIngestionPortInterface
{
    /**
     * @param string  $path          an existing readable file; the implementation moves it, it is gone afterwards
     * @param string  $filename      the name the recording is recorded under, used for its extension
     * @param string  $recordingType `MIXED`, `CALLER` or `CALLEE`
     * @param ?string $orderId       digits, or null when the call names no order
     * @param string  $provider      `WHISPER` or `DEEPGRAM`
     * @param bool    $generateAiAudio whether clean audio was asked for **on this request**
     */
    public function ingestFile(
        int $storeSourceId,
        string $path,
        string $filename,
        string $recordingType,
        ?string $orderId,
        string $provider,
        bool $generateAiAudio,
        int $adminUserId,
    ): AudioIngestionOutcome;
}
