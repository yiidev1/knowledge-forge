<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Shared\Audio\AudioIngestionOutcome;
use App\Shared\Audio\AudioIngestionPortInterface;
use HttpSoft\Message\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

use function basename;
use function filesize;
use function is_file;
use function is_readable;

use const UPLOAD_ERR_OK;

/**
 * The one way a single recording enters this application, whoever is offering it.
 *
 * ## Why this exists
 *
 * Validation and enqueueing were two steps a caller had to remember to perform in order.
 * {@see AudioUploadValidator} checks the size, the extension and the actual bytes;
 * {@see TranscriptionQueue} checks only the duration. So a caller that went straight to the queue got
 * **no size check and no type check at all** — and one already did: the Manage Audio replacement upload
 * reached `enqueueConversation()` without ever consulting the validator, which meant a 500 MB file or a
 * renamed executable was refused on the store page and accepted on the replacement form.
 *
 * Composing the two here, and routing every caller through it, makes that class of mistake unavailable
 * rather than merely unlikely. A new caller cannot skip validation, because there is nothing to skip: the
 * only public method does both.
 *
 * ## What it deliberately does not do
 *
 * It does not decide *which* provider or *whether* to buy AI audio — those arrive as arguments, from
 * {@see UploadOptions}, which is the one authority over them. It does not open the file, name it, or
 * choose where it is stored; `TranscriptionQueue` still owns all of that, unchanged. And it takes one
 * recording, not a pair: the legacy two-file SEPARATE upload has its own validator and its own shape, and
 * folding both into one signature would produce a method whose arguments contradict each other.
 */
final readonly class AudioIngestionService implements AudioIngestionPortInterface
{
    public function __construct(
        private AudioUploadValidator $validator,
        private TranscriptionQueue $queue,
    ) {}

    /**
     * Validate this recording and, if it passes, queue it for transcription.
     *
     * Rejection is a **return value**, not an exception: an unusable upload is an ordinary thing a person
     * does, and every caller has somewhere to put the sentences. A failure of this application — no disk,
     * a recording longer than the configured limit, a queue at its ceiling — is still an
     * `AudioTranscriptionException` and still propagates, because those are not the uploader's fault and
     * the callers already say so in their own words.
     *
     * @param ?string $orderId already validated by the caller, or null when none was given
     *
     * @throws \App\AudioToText\Domain\AudioTranscriptionException
     */
    public function ingest(
        int $storeSourceId,
        UploadedFileInterface $file,
        RecordingType $recordingType,
        ?string $orderId,
        TranscriptionProvider $provider,
        bool $generateAiAudio,
        int $adminUserId,
    ): IngestionResult {
        $problems = $this->validator->validate($file);

        if ($problems !== []) {
            return IngestionResult::rejected($problems);
        }

        // One file, one recording — the shape every modern upload has. SEPARATE is the legacy pair and is
        // not reachable from here; see the class docblock.
        return IngestionResult::queued($this->queue->enqueueConversation(
            ConversationMode::Common,
            $storeSourceId,
            [SourceRole::Common->value => $file],
            $adminUserId,
            $provider,
            $generateAiAudio,
            $recordingType,
            $orderId,
        ));
    }

    /**
     * The same ingestion, offered a file on disk rather than an upload.
     *
     * This is the seam {@see AudioIngestionPortInterface} describes: a caller in another module hands
     * over a path and two storage values, and everything that follows — wrapping the bytes, validating
     * them, queueing them — happens on this side, where the rules live.
     *
     * The file is **moved**, not copied: `QueuedAudioStorage::store()` calls `moveTo()`, so the caller's
     * temporary file is gone afterwards whether or not the recording was accepted. Callers are expected
     * to treat the path as consumed.
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
    ): AudioIngestionOutcome {
        $type = RecordingType::fromStorage($recordingType);
        $engine = TranscriptionProvider::fromStorage($provider);

        // An unrecognised value is a rejection rather than a default. Quietly transcribing with Whisper
        // because a caller sent a provider name this build does not know would report success for a
        // choice nobody made.
        if ($type === null || $engine === null) {
            return AudioIngestionOutcome::rejected([
                'That recording type or transcription provider is not one this server offers.',
            ]);
        }

        if (!is_file($path) || !is_readable($path)) {
            return AudioIngestionOutcome::unavailable('The downloaded recording could not be read.');
        }

        $size = filesize($path);

        if ($size === false || $size <= 0) {
            return AudioIngestionOutcome::rejected(['The downloaded recording is empty.']);
        }

        // The client filename is what the validator reads the extension from, and what the storage layer
        // sanitises; the bytes themselves are still sniffed, so a misleading name changes nothing.
        $file = new UploadedFile($path, $size, UPLOAD_ERR_OK, basename($filename), 'audio/wav');

        try {
            $result = $this->ingest(
                $storeSourceId,
                $file,
                $type,
                $orderId,
                $engine,
                $generateAiAudio,
                $adminUserId,
            );
        } catch (AudioTranscriptionException $e) {
            // Most of what this exception covers is about *this server* — no temporary directory, a
            // queue at its ceiling, a probe that would not run — and is worth another attempt later.
            // A recording past the duration limit is not: it will be exactly as long next time, so it
            // is reported as a refusal and the caller records it instead of retrying it for ever.
            return $e->recordingIsUnusable()
                ? AudioIngestionOutcome::rejected([$e->getMessage()])
                : AudioIngestionOutcome::unavailable($e->getMessage());
        }

        if ($result->wasQueued()) {
            return AudioIngestionOutcome::queued((string) $result->conversationPublicId);
        }

        $problems = $result->problems;

        return AudioIngestionOutcome::rejected(
            $problems === [] ? ['The recording could not be accepted.'] : $problems,
        );
    }
}
