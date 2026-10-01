<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\Integration\Order58Recording\RecordingChannel;
use DateTimeImmutable;

/**
 * One recording channel's import, as both the worker and the history table need it.
 *
 * One object rather than two because the two readers want almost the same fields and the difference is
 * not worth a second query shape: the worker needs everything required to build the provider request,
 * and the page needs everything required to explain the outcome. The only field neither shares is the
 * error, which the page shows and the worker writes.
 *
 * The provider request is assembled from `recordingCompany`, `callDate` and `callSessionId` — all three
 * carried here rather than looked up, because all three were fixed when the batch was created and must
 * not drift afterwards. See {@see \App\Order58\Application\RecordingCompanyResolver} for why the company
 * in particular is a snapshot.
 */
final readonly class CallImportItem
{
    public function __construct(
        public int $id,
        public int $batchId,
        public int $storeSourceId,
        public string $callSessionId,
        public RecordingChannel $channel,
        public string $callTimeRaw,
        /** `YYYY-MM-DD`, from this call's own time — never from today's date. */
        public string $callDate,
        public ?string $orderId,
        public Order58ImportStatus $status,
        public int $attempts,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?int $bytes,
        public ?float $durationSeconds,
        public ?string $conversationPublicId,
        public ?DateTimeImmutable $completedAt,
        public DateTimeImmutable $updatedAt,
        // From the batch, so the worker needs one row rather than a join per item.
        public string $recordingCompany,
        /**
         * A storage value such as `WHISPER`, not an enum.
         *
         * This module may not name Audio-to-Text's `TranscriptionProvider` — the isolation test forbids
         * it — so the provider travels as the string the column stores, exactly as
         * {@see AudioProviderDefaultInterface} already hands it to the store-audio page.
         */
        public string $provider,
        public bool $generateAiAudio,
        public int $requestedByAdminId,
        /**
         * What the batch this item belongs to asked for.
         *
         * Read by the import worker once the file is stored, to decide whether to ask the audio pipeline
         * for a transcript as well. Defaulted so a row read through an older code path behaves as
         * imports always have.
         */
        public CallImportMode $mode = CallImportMode::DownloadAndTranscribe,

        /**
         * When a person asked for this recording.
         *
         * Distinct from {@see $updatedAt}, which moves with every attempt, and from
         * {@see $completedAt}, which is when the provider finished answering. The history page shows
         * this as **Requested**, and calling it anything to do with syncing would be describing a
         * different operation: nothing was synchronised, somebody pressed a button.
         *
         * Last and defaulted, so adding it shifts no existing positional argument.
         */
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
