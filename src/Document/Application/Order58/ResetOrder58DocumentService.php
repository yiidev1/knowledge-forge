<?php

declare(strict_types=1);

namespace App\Document\Application\Order58;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\Exception\Order58MirrorMissing;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Order58\Application\Formatter\Order58KnowledgeFormatter;
use App\Order58\Application\Formatter\Order58StoreProfileFormatter;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Throwable;

use function hash;
use function strlen;

/**
 * Discards a local override and restores the document from the local Order58 mirror. No API / OpenAI call.
 */
final readonly class ResetOrder58DocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private Order58StoreRepositoryInterface $stores,
        private Order58KnowledgeRepositoryInterface $knowledge,
        private Order58StoreProfileFormatter $storeFormatter,
        private Order58KnowledgeFormatter $knowledgeFormatter,
        private ClockInterface $clock,
    ) {}

    public function reset(int $documentId, int $knowledgeBaseId): void
    {
        $document = $this->documents->findCanonicalForKnowledgeBase($documentId, $knowledgeBaseId);
        if ($document === null || !$document->isOrder58() || $document->sourceRef === null || $document->sourceRef === '') {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        [$title, $text, $syncHash] = $this->reconstruct($document->sourceType, $document->sourceRef);

        $checksum = hash('sha256', $document->sourceType->value . "\0" . $document->sourceRef . "\0" . $text);
        $size = strlen($text);
        $now = $this->clock->now();

        $previous = '';
        if ($this->storage->exists($document->storedPath)) {
            $stream = $this->storage->readStream($document->storedPath);
            $previous = $stream->getContents();
            $stream->close();
        }

        try {
            $this->storage->putContents($document->storedPath, $text);
            $this->documents->clearSourceOverride($documentId, $title, $syncHash, $checksum, $size, $now);
            $this->indexedFiles->markPendingRemovalByDocument($documentId);
            $this->events->record($documentId, 'queued', 'Reset to Order58 version and queued for re-indexing.', [
                'source_type' => $document->sourceType->value,
                'source_ref' => $document->sourceRef,
            ]);
        } catch (Throwable $e) {
            if ($previous !== '') {
                $this->storage->putContents($document->storedPath, $previous);
            }
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} title, body, syncHash
     */
    private function reconstruct(DocumentSourceType $sourceType, string $sourceRef): array
    {
        $sourceId = (int) $sourceRef;

        if ($sourceType === DocumentSourceType::Order58StoreProfile) {
            $store = $this->stores->findBySourceId($sourceId);
            if ($store === null) {
                throw Order58MirrorMissing::forSource();
            }

            return [
                'Store profile: ' . $store->name,
                $this->storeFormatter->format($store->snapshot),
                $store->syncHash,
            ];
        }

        $record = $this->knowledge->findBySourceId($sourceId);
        if ($record === null) {
            throw Order58MirrorMissing::forSource();
        }

        $title = $record->title !== '' ? $record->title : ('Knowledge #' . $record->sourceId);

        return [
            $title,
            $this->knowledgeFormatter->format(
                $record->storeSourceId,
                $record->sourceId,
                $record->title,
                $record->content,
                $record->type,
                $record->keyword,
                $record->knowledgeNumber,
            ),
            $record->syncHash,
        ];
    }
}
