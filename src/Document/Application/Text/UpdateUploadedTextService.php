<?php

declare(strict_types=1);

namespace App\Document\Application\Text;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Yiisoft\Db\Exception\IntegrityException;

use function hash;
use function mb_strlen;
use function mb_substr;
use function strlen;
use function trim;

/**
 * Edits an uploaded .txt/.md document's title and canonical local file. Never calls OpenAI.
 */
final readonly class UpdateUploadedTextService
{
    private const TITLE_MAX = 200;
    private const CONTENT_MAX = 100_000;

    public function __construct(
        private TextDocumentRepositoryInterface $texts,
        private DocumentStorageInterface $storage,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {}

    public function update(int $documentId, int $knowledgeBaseId, string $title, string $content): TextUpdateOutcome
    {
        $document = $this->texts->findEditable($documentId, $knowledgeBaseId);
        if ($document === null || !$document->isUploadedText()) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        $title = $this->validateTitle($title);
        $normalized = $this->normalize($content);
        $checksum = hash('sha256', $normalized);
        $now = $this->clock->now();

        if ($checksum === $document->checksum) {
            $this->texts->updateMetadata($documentId, $title, $normalized, $now);
            $this->events->record($documentId, 'updated', 'Uploaded text edited; content unchanged, not re-indexed.');

            return TextUpdateOutcome::Unchanged;
        }

        try {
            $this->texts->replaceContent($documentId, $title, $normalized, $checksum, strlen($normalized), $now);
        } catch (IntegrityException) {
            throw DuplicateDocument::inKnowledgeBase();
        }

        $this->storage->putContents($document->storedPath, $normalized);
        $this->indexedFiles->markPendingRemovalByDocument($documentId);
        $this->events->record($documentId, 'queued', 'Uploaded text edited and queued for re-indexing.', [
            'source_type' => DocumentSourceType::UploadedText->value,
        ]);

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
