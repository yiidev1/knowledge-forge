<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Storage\StoragePathException;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Application\Validation\UploadValidator;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\Exception\UnsupportedDocumentType;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Throwable;

use function bin2hex;
use function hash_file;
use function is_file;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function random_bytes;
use function rename;
use function str_replace;
use function strrpos;
use function substr;
use function trim;
use function unlink;

/**
 * Title update and atomic type-compatible PDF/image replacement. Never calls OpenAI.
 */
final readonly class ReplaceBinaryDocumentService
{
    private const TITLE_MAX = 200;

    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private UploadValidator $validator,
        private IndexedFileRepositoryInterface $indexedFiles,
        private ProcessingEventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {}

    /**
     * @param string|null $temporaryAbsolutePath Replacement upload temp path, or null for title-only.
     */
    public function update(
        int $documentId,
        int $knowledgeBaseId,
        string $title,
        ?string $temporaryAbsolutePath,
    ): TextUpdateOutcome {
        $document = $this->documents->findCanonicalForKnowledgeBase($documentId, $knowledgeBaseId);
        if ($document === null || !$document->isBinaryUpload()) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBaseId);
        }

        $title = $this->validateTitle($title);
        $now = $this->clock->now();

        if ($temporaryAbsolutePath === null || $temporaryAbsolutePath === '') {
            if ($title === $document->displayTitle()) {
                return TextUpdateOutcome::Unchanged;
            }
            $this->documents->updateTitle($documentId, $title, $now);
            $this->events->record($documentId, 'updated', 'Title updated; content unchanged, not re-indexed.');

            return TextUpdateOutcome::Unchanged;
        }

        try {
            $validated = $this->validator->validate($temporaryAbsolutePath);
            if ($validated->kind !== $document->kind) {
                $accepted = $document->kind === DocumentKind::Pdf
                    ? ['application/pdf']
                    : ['image/png', 'image/jpeg', 'image/webp'];
                throw UnsupportedDocumentType::detected($validated->mimeType, $accepted);
            }

            $checksum = hash_file('sha256', $temporaryAbsolutePath);
            if ($checksum === false) {
                throw new StoragePathException('Could not read the uploaded file to checksum it.');
            }

            if ($this->documents->liveChecksumExists($checksum, $knowledgeBaseId, $documentId)) {
                throw DuplicateDocument::inKnowledgeBase();
            }

            $displayName = $this->sanitizeDisplayName($document->originalFilename, $validated->extension);
            $absoluteTarget = $this->storage->absolutePath($document->storedPath);
            $backup = $absoluteTarget . '.bak.' . bin2hex(random_bytes(4));
            $movedAside = false;

            try {
                if (is_file($absoluteTarget)) {
                    if (!@rename($absoluteTarget, $backup)) {
                        throw new StoragePathException('Could not protect the existing file during replacement.');
                    }
                    $movedAside = true;
                }

                if (!@rename($temporaryAbsolutePath, $absoluteTarget)) {
                    throw new StoragePathException('Could not store the replacement file.');
                }

                $this->documents->replaceBinarySource(
                    $documentId,
                    $title,
                    $displayName,
                    $validated->mimeType,
                    $validated->extension,
                    $validated->sizeBytes,
                    $checksum,
                    $now,
                );

                // Derived Markdown cleanup only after a successful commit.
                $derived = $this->storage->derivedMarkdownPath($knowledgeBaseId, $document->storageToken);
                if ($this->storage->exists($derived)) {
                    $this->storage->delete($derived);
                }

                $this->indexedFiles->markPendingRemovalByDocument($documentId);
                $this->events->record($documentId, 'queued', 'Source file replaced and queued for re-indexing.', [
                    'kind' => $document->kind->value,
                    'size_bytes' => $validated->sizeBytes,
                ]);

                if (is_file($backup)) {
                    @unlink($backup);
                }

                return TextUpdateOutcome::Reindexed;
            } catch (Throwable $e) {
                if ($movedAside && is_file($backup) && !is_file($absoluteTarget)) {
                    @rename($backup, $absoluteTarget);
                } elseif (is_file($backup)) {
                    @unlink($backup);
                }
                throw $e;
            }
        } finally {
            $this->storage->discard($temporaryAbsolutePath);
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

    private function sanitizeDisplayName(string $originalFilename, string $extension): string
    {
        $name = str_replace(["\0", "\r", "\n"], '', $originalFilename);
        $name = preg_replace('/[\/\\\\]+/', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'document.' . $extension;
        }
        $dot = strrpos($name, '.');
        if ($dot === false || strtolower(substr($name, $dot + 1)) !== strtolower($extension)) {
            $name = ($dot === false ? $name : substr($name, 0, $dot)) . '.' . $extension;
        }

        return mb_substr($name, 0, max(1, 255));
    }
}
