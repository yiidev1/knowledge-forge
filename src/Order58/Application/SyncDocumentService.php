<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\GeneratedDocumentRepositoryInterface;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use DateTimeImmutable;

use function hash;
use function strlen;

/**
 * Upserts an Order58-generated document through the existing document→index pipeline, without ever calling
 * OpenAI itself — it writes the normalized text and queues the document; the background processing drainer
 * indexes it.
 *
 * Change detection is by `_sync_hash`: an unchanged record is left completely untouched (no rewrite, no
 * re-index). A changed record's text is rewritten, its old index files are flagged for removal (so the old
 * vector-store copy survives until the replacement indexes), and the document is requeued fresh. A
 * disappeared record's document is disabled and scheduled for removal, preserving audit history.
 *
 * When {@see GeneratedDocument::$isSourceOverridden} is true, upstream changes still update mirror tables
 * (handled by sync handlers) but this service leaves the local document title and file alone until an
 * administrator resets the override.
 */
final readonly class SyncDocumentService
{
    public function __construct(
        private GeneratedDocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private SafeFilenameGenerator $filenames,
    ) {}

    public function upsertGenerated(
        int $knowledgeBaseId,
        DocumentSourceType $sourceType,
        string $sourceRef,
        string $title,
        string $syncHash,
        string $text,
        DateTimeImmutable $now,
        bool $force = false,
    ): GeneratedDocumentUpsert {
        $existing = $this->documents->findBySource($knowledgeBaseId, $sourceType->value, $sourceRef);

        if (!$force && $existing !== null && !$existing->isDeleted() && $existing->sourceSyncHash === $syncHash) {
            return GeneratedDocumentUpsert::Unchanged;
        }

        if ($existing !== null && !$existing->isDeleted() && $existing->isSourceOverridden) {
            // Local override wins: do not overwrite title/body, do not requeue.
            return GeneratedDocumentUpsert::SkippedOverride;
        }

        if ($existing !== null && $existing->isAdminDisabled()) {
            // An admin explicitly disabled this document. A resync (even a forced rebuild) must not silently
            // re-enable it or re-add its vector-store file; it stays disabled until the admin re-enables it,
            // and it never counts toward chat availability. Its indexed files are cleaned up normally.
            return GeneratedDocumentUpsert::SkippedDisabled;
        }

        // Salt the content checksum with the source ref so two distinct source records with identical text
        // are never merged by the per-knowledge-base content-dedupe index.
        $checksum = hash('sha256', $sourceType->value . "\0" . $sourceRef . "\0" . $text);
        $size = strlen($text);
        $metadata = ['source_type' => $sourceType->value, 'source_ref' => $sourceRef];

        if ($existing === null) {
            $token = $this->filenames->token();
            $storedPath = $this->storage->derivedMarkdownPath($knowledgeBaseId, $token);
            $this->storage->putContents($storedPath, $text);

            $id = $this->documents->create(
                $knowledgeBaseId,
                $sourceType->value,
                $sourceRef,
                $title,
                $syncHash,
                $storedPath,
                $token,
                $checksum,
                $size,
                $now,
            );
            $this->events->record($id, 'queued', 'Generated from Order58 source.', $metadata);

            return GeneratedDocumentUpsert::Created;
        }

        $this->storage->putContents($existing->storedPath, $text);
        // Old vector-store files are flagged for the cleanup drainer; they stay attached until the
        // reprocess produces the replacement, so the knowledge base is never left with no usable copy.
        $this->indexedFiles->markPendingRemovalByDocument($existing->id);
        $this->documents->reindex($existing->id, $title, $syncHash, $checksum, $size, $now);
        $this->events->record($existing->id, 'queued', 'Regenerated from changed Order58 source.', $metadata);

        return GeneratedDocumentUpsert::Updated;
    }

    /**
     * Disables the generated document for a source record that disappeared or went inactive.
     */
    public function disableGenerated(
        int $knowledgeBaseId,
        DocumentSourceType $sourceType,
        string $sourceRef,
        DateTimeImmutable $now,
    ): void {
        $existing = $this->documents->findBySource($knowledgeBaseId, $sourceType->value, $sourceRef);
        if ($existing === null || $existing->isDeleted()) {
            return;
        }

        $this->indexedFiles->markPendingRemovalByDocument($existing->id);
        $this->documents->disable($existing->id, $now);
        $this->events->record(
            $existing->id,
            'deleted',
            'Order58 source record deactivated.',
            ['source_type' => $sourceType->value, 'source_ref' => $sourceRef],
        );
    }
}
