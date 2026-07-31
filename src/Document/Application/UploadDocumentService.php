<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Text\PlainTextNormalizer;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Application\Validation\UploadValidator;
use App\Document\Application\Validation\ValidatedUpload;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\Exception\DocumentLimitReached;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\NewDocument;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use Throwable;
use Yiisoft\Db\Exception\IntegrityException;

use function file_get_contents;
use function hash;
use function hash_file;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * The one place an upload becomes a stored, queued document.
 *
 * Validation and persistence in a fixed, security-critical order: enforce the per-base limit, validate the
 * real bytes, then persist. Binary uploads (PDF, image) are streamed to storage as-is; text uploads
 * (.txt/.md) are normalized to UTF-8 first, so the indexed artifact is deterministic and the per-base
 * content dedupe compares normalized text. No OpenAI work happens here — the background worker takes it
 * from `queued`. The just-placed file is removed if the insert fails, so no orphan is left.
 */
final readonly class UploadDocumentService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private ProcessingEventRepositoryInterface $events,
        private DocumentStorageInterface $storage,
        private UploadValidator $validator,
        private SafeFilenameGenerator $filenames,
        private TransactionRunnerInterface $transaction,
        private DocumentProcessingParams $params,
    ) {}

    /**
     * @param string $temporaryAbsolutePath A file the caller has already captured the upload into. It is
     *                                       always consumed (moved or discarded) by the time this returns.
     *
     * @return int The id of the created, queued document.
     *
     * @throws DocumentLimitReached
     * @throws DuplicateDocument
     * @throws \App\Document\Domain\Exception\UploadException on validation failure.
     */
    public function upload(int $knowledgeBaseId, string $originalFilename, string $temporaryAbsolutePath): int
    {
        try {
            if ($this->documents->countLiveForKnowledgeBase($knowledgeBaseId) >= $this->params->maxDocumentsPerKnowledgeBase) {
                throw DocumentLimitReached::limit($this->params->maxDocumentsPerKnowledgeBase);
            }

            $validated = $this->validator->validate($temporaryAbsolutePath);

            return $validated->kind === DocumentKind::Text
                ? $this->storeText($knowledgeBaseId, $originalFilename, $temporaryAbsolutePath, $validated)
                : $this->storeBinary($knowledgeBaseId, $originalFilename, $temporaryAbsolutePath, $validated);
        } finally {
            // No-op once the file has been moved; cleans up on any pre-move failure.
            $this->storage->discard($temporaryAbsolutePath);
        }
    }

    private function storeBinary(int $knowledgeBaseId, string $originalFilename, string $temporaryAbsolutePath, ValidatedUpload $validated): int
    {
        $checksum = hash_file('sha256', $temporaryAbsolutePath);
        if ($checksum === false) {
            throw new Storage\StoragePathException('Could not read the uploaded file to checksum it.');
        }

        if ($this->documents->liveChecksumExists($checksum, $knowledgeBaseId)) {
            throw DuplicateDocument::inKnowledgeBase();
        }

        $token = $this->filenames->token();
        $storedFilename = $this->filenames->filename($token, $validated->extension);
        $displayName = $this->sanitizeDisplayName($originalFilename, $validated->extension);
        $relativePath = $this->storage->moveInto($temporaryAbsolutePath, $knowledgeBaseId, $storedFilename);

        return $this->persist(
            $knowledgeBaseId,
            $displayName,
            $relativePath,
            $token,
            $validated,
            $validated->sizeBytes,
            $checksum,
            $validated->kind,
            $this->sourceTypeFor($validated->kind),
            'Uploaded and queued for processing.',
        );
    }

    private function storeText(int $knowledgeBaseId, string $originalFilename, string $temporaryAbsolutePath, ValidatedUpload $validated): int
    {
        $raw = @file_get_contents($temporaryAbsolutePath);
        if ($raw === false) {
            throw new Storage\StoragePathException('Could not read the uploaded text file.');
        }

        $normalized = PlainTextNormalizer::normalize($raw);
        if ($normalized === '') {
            throw InvalidText::empty();
        }

        $checksum = hash('sha256', $normalized);
        if ($this->documents->liveChecksumExists($checksum, $knowledgeBaseId)) {
            throw DuplicateDocument::inKnowledgeBase();
        }

        $token = $this->filenames->token();
        $storedPath = $this->storage->derivedMarkdownPath($knowledgeBaseId, $token);
        $displayName = $this->sanitizeDisplayName($originalFilename, $validated->extension);
        $this->storage->putContents($storedPath, $normalized);

        return $this->persist(
            $knowledgeBaseId,
            $displayName,
            $storedPath,
            $token,
            $validated,
            strlen($normalized),
            $checksum,
            DocumentKind::Text,
            DocumentSourceType::UploadedText,
            'Text file uploaded and queued for indexing.',
        );
    }

    private function persist(
        int $knowledgeBaseId,
        string $displayName,
        string $relativePath,
        string $token,
        ValidatedUpload $validated,
        int $sizeBytes,
        string $checksum,
        DocumentKind $kind,
        DocumentSourceType $sourceType,
        string $eventMessage,
    ): int {
        try {
            return $this->transaction->run(function () use (
                $knowledgeBaseId,
                $displayName,
                $relativePath,
                $token,
                $validated,
                $sizeBytes,
                $checksum,
                $kind,
                $sourceType,
                $eventMessage,
            ): int {
                $id = $this->documents->createQueued(new NewDocument(
                    knowledgeBaseId: $knowledgeBaseId,
                    originalFilename: $displayName,
                    storedPath: $relativePath,
                    storageToken: $token,
                    mimeType: $validated->mimeType,
                    extension: $validated->extension,
                    sizeBytes: $sizeBytes,
                    checksumSha256: $checksum,
                    kind: $kind,
                    sourceType: $sourceType,
                    title: $displayName,
                ));

                $this->events->record($id, 'queued', $eventMessage, [
                    'kind' => $kind->value,
                    'source_type' => $sourceType->value,
                    'size_bytes' => $sizeBytes,
                ]);

                return $id;
            });
        } catch (IntegrityException) {
            $this->storage->delete($relativePath);
            throw DuplicateDocument::inKnowledgeBase();
        } catch (Throwable $e) {
            $this->storage->delete($relativePath);
            throw $e;
        }
    }

    private function sourceTypeFor(DocumentKind $kind): DocumentSourceType
    {
        return match ($kind) {
            DocumentKind::Pdf => DocumentSourceType::UploadedPdf,
            DocumentKind::Image => DocumentSourceType::UploadedImage,
            DocumentKind::Text => DocumentSourceType::UploadedText,
        };
    }

    /**
     * The original filename is display-only and always HTML-escaped when rendered, but it is still
     * cleaned here: strip any directory portion, remove control characters and cap the length.
     */
    private function sanitizeDisplayName(string $original, string $extension): string
    {
        $slash = strrpos($original, '/');
        $backslash = strrpos($original, '\\');
        $cut = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash);
        $name = $cut >= 0 ? substr($original, $cut + 1) : $original;

        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = str_replace("\0", '', $name);
        $name = trim($name);

        if ($name === '') {
            $name = 'upload.' . $extension;
        }

        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return $name;
    }
}
