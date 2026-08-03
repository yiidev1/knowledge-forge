<?php

declare(strict_types=1);

namespace App\Document\Application\Order58;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Text\PlainTextNormalizer;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Throwable;
use Yiisoft\Db\Exception\IntegrityException;

use function hash;
use function mb_strlen;
use function mb_substr;
use function strlen;
use function trim;

/**
 * Creates a persistent local override for an Order58-generated document. Never calls OpenAI.
 */
final readonly class UpdateOrder58DocumentService
{
    private const TITLE_MAX = 200;
    private const CONTENT_MAX = 100_000;

    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {}

    public function update(int $documentId, int $knowledgeBaseId, string $title, string $content): TextUpdateOutcome
    {
        $document = $this->documents->findCanonicalForKnowledgeBase($documentId, $knowledgeBaseId);
        if ($document === null || !$document->isOrder58()) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        $title = $this->validateTitle($title);
        $normalized = $this->normalize($content);
        $checksum = hash('sha256', $normalized);
        $now = $this->clock->now();

        $titleChanged = $title !== $document->displayTitle();
        $bodyChanged = $checksum !== $document->checksumSha256;

        if (!$titleChanged && !$bodyChanged) {
            return TextUpdateOutcome::Unchanged;
        }

        $previous = '';
        if ($this->storage->exists($document->storedPath)) {
            $stream = $this->storage->readStream($document->storedPath);
            $previous = $stream->getContents();
            $stream->close();
        }

        try {
            $this->storage->putContents($document->storedPath, $normalized);
            $this->documents->applySourceOverride(
                $documentId,
                $title,
                $checksum,
                strlen($normalized),
                $now,
                requeue: $bodyChanged,
            );

            if ($bodyChanged) {
                $this->indexedFiles->markPendingRemovalByDocument($documentId);
                $this->events->record($documentId, 'queued', 'Order58 document locally overridden and queued for re-indexing.', [
                    'source_type' => $document->sourceType->value,
                ]);

                return TextUpdateOutcome::Reindexed;
            }

            $this->events->record($documentId, 'updated', 'Order58 document title updated with local override.');

            return TextUpdateOutcome::Unchanged;
        } catch (IntegrityException) {
            if ($previous !== '') {
                $this->storage->putContents($document->storedPath, $previous);
            }
            throw DuplicateDocument::inKnowledgeBase();
        } catch (Throwable $e) {
            if ($previous !== '') {
                $this->storage->putContents($document->storedPath, $previous);
            }
            throw $e;
        }
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
