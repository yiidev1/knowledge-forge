<?php

declare(strict_types=1);

namespace App\Document\Application\Text;

use App\Document\Application\DocumentProcessingParams;
use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\Exception\DocumentLimitReached;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\NewDocument;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Clock\ClockInterface;
use Throwable;
use Yiisoft\Db\Exception\IntegrityException;

use function hash;
use function mb_strlen;
use function mb_substr;
use function strlen;
use function trim;

/**
 * Creates and edits manual-text knowledge — an admin types a title and content, and it flows through the
 * same worker-driven indexing pipeline as any other document.
 *
 * The original submitted text is stored verbatim (for later editing); the indexed artifact is always the
 * deterministic normalized text. On edit, the normalized content is re-hashed: an unchanged edit touches
 * only the title/original and never re-indexes; a changed edit rewrites the text, flags the old
 * vector-store file for removal, and requeues — so the previous copy stays until the replacement is ready.
 * Nothing here calls OpenAI; the worker does the indexing.
 */
final readonly class ManualTextService
{
    private const TITLE_MAX = 200;
    private const CONTENT_MAX = 100_000;
    private const MIME = 'text/markdown';

    public function __construct(
        private DocumentRepositoryInterface $documents,
        private TextDocumentRepositoryInterface $texts,
        private ProcessingEventRepositoryInterface $events,
        private IndexedFileRepositoryInterface $indexedFiles,
        private DocumentStorageInterface $storage,
        private SafeFilenameGenerator $filenames,
        private TransactionRunnerInterface $transaction,
        private DocumentProcessingParams $params,
        private ClockInterface $clock,
    ) {}

    /**
     * @return int The new document id.
     *
     * @throws DocumentLimitReached
     * @throws DuplicateDocument
     * @throws InvalidText
     */
    public function create(int $knowledgeBaseId, string $title, string $content): int
    {
        $title = $this->validateTitle($title);
        $normalized = $this->normalize($content);

        if ($this->documents->countLiveForKnowledgeBase($knowledgeBaseId) >= $this->params->maxDocumentsPerKnowledgeBase) {
            throw DocumentLimitReached::limit($this->params->maxDocumentsPerKnowledgeBase);
        }

        $checksum = hash('sha256', $normalized);
        if ($this->documents->liveChecksumExists($checksum, $knowledgeBaseId)) {
            throw DuplicateDocument::inKnowledgeBase();
        }

        $token = $this->filenames->token();
        $storedPath = $this->storage->derivedMarkdownPath($knowledgeBaseId, $token);
        $this->storage->putContents($storedPath, $normalized);
        $size = strlen($normalized);

        try {
            return $this->transaction->run(function () use ($knowledgeBaseId, $title, $content, $storedPath, $token, $checksum, $size): int {
                $id = $this->documents->createQueued(new NewDocument(
                    knowledgeBaseId: $knowledgeBaseId,
                    originalFilename: $title,
                    storedPath: $storedPath,
                    storageToken: $token,
                    mimeType: self::MIME,
                    extension: 'md',
                    sizeBytes: $size,
                    checksumSha256: $checksum,
                    kind: DocumentKind::Text,
                    sourceType: DocumentSourceType::ManualText,
                    title: $title,
                    sourceText: $content,
                ));
                $this->events->record($id, 'queued', 'Manual text created and queued for indexing.', [
                    'source_type' => DocumentSourceType::ManualText->value,
                    'size_bytes' => $size,
                ]);

                return $id;
            });
        } catch (IntegrityException) {
            $this->storage->delete($storedPath);
            throw DuplicateDocument::inKnowledgeBase();
        } catch (Throwable $e) {
            $this->storage->delete($storedPath);
            throw $e;
        }
    }

    /**
     * @throws NotFoundException when the document is missing or is not manual text.
     * @throws DuplicateDocument when the new content duplicates another live document.
     * @throws InvalidText
     */
    public function update(int $documentId, int $knowledgeBaseId, string $title, string $content): TextUpdateOutcome
    {
        $document = $this->texts->findEditable($documentId, $knowledgeBaseId);
        if ($document === null || !$document->isManual()) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        $title = $this->validateTitle($title);
        $normalized = $this->normalize($content);
        $checksum = hash('sha256', $normalized);
        $now = $this->clock->now();

        if ($checksum === $document->checksum) {
            // Content unchanged — update only the title/original text, and never re-index.
            $this->texts->updateMetadata($documentId, $title, $content, $now);
            $this->events->record($documentId, 'updated', 'Manual text edited; content unchanged, not re-indexed.');

            return TextUpdateOutcome::Unchanged;
        }

        try {
            $this->texts->replaceContent($documentId, $title, $content, $checksum, strlen($normalized), $now);
        } catch (IntegrityException) {
            throw DuplicateDocument::inKnowledgeBase();
        }

        // Rewrite the indexed text, then flag the old vector-store file for removal. It stays attached
        // until the requeued re-index produces the replacement, so retrieval is never left with no copy.
        $this->storage->putContents($document->storedPath, $normalized);
        $this->indexedFiles->markPendingRemovalByDocument($documentId);
        $this->events->record($documentId, 'queued', 'Manual text edited and queued for re-indexing.');

        return TextUpdateOutcome::Reindexed;
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw InvalidText::titleRequired();
        }

        return mb_strlen($title) > self::TITLE_MAX ? mb_substr($title, 0, self::TITLE_MAX) : $title;
    }

    private function normalize(string $content): string
    {
        if (!PlainTextNormalizer::isValidUtf8($content)) {
            throw InvalidText::notUtf8();
        }

        $normalized = PlainTextNormalizer::normalize($content);
        if ($normalized === '') {
            throw InvalidText::empty();
        }
        if (mb_strlen($normalized) > self::CONTENT_MAX) {
            throw InvalidText::tooLong(self::CONTENT_MAX);
        }

        return $normalized;
    }
}
