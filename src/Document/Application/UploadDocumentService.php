<?php

declare(strict_types=1);

namespace App\Document\Application;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Application\Validation\UploadValidator;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\DocumentLimitReached;
use App\Document\Domain\NewDocument;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use Throwable;
use Yiisoft\Db\Exception\IntegrityException;

use function hash_file;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function str_replace;
use function strrpos;
use function substr;
use function trim;

/**
 * The one place an upload becomes a stored, queued document.
 *
 * Deliberately kept out of the HTTP action: it takes a captured temporary file (not a PSR-7 upload) so
 * it is fully testable, and it does the security-critical work in a fixed order — enforce the per-base
 * limit, validate the real bytes, checksum and de-duplicate, then move into storage and insert a
 * `queued` row. No OpenAI work happens here; the background worker takes it from `queued` (Phase 6).
 *
 * Consistency between disk and database is maintained explicitly: the file is placed first, then the
 * row inserted in a transaction; if the insert fails — including a duplicate racing past the pre-check
 * into the unique index — the just-placed file is removed so no orphan is left.
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

            // Validates the real bytes (server-side MIME sniff, size, image decode + dimensions).
            $validated = $this->validator->validate($temporaryAbsolutePath);

            // Streamed by PHP internally, so a large PDF is not read into memory.
            $checksum = hash_file('sha256', $temporaryAbsolutePath);
            if ($checksum === false) {
                throw new Storage\StoragePathException('Could not read the uploaded file to checksum it.');
            }

            // Fast path for the common duplicate; the unique index is the real guarantee (see below).
            if ($this->documents->liveChecksumExists($checksum, $knowledgeBaseId)) {
                throw DuplicateDocument::inKnowledgeBase();
            }

            $token = $this->filenames->token();
            $storedFilename = $this->filenames->filename($token, $validated->extension);
            $displayName = $this->sanitizeDisplayName($originalFilename, $validated->extension);

            // Place the file, then insert. Ordering the file first means a failed insert leaves at most
            // an orphan file (swept later), never a row pointing at nothing.
            $relativePath = $this->storage->moveInto($temporaryAbsolutePath, $knowledgeBaseId, $storedFilename);

            try {
                return $this->transaction->run(function () use (
                    $knowledgeBaseId,
                    $displayName,
                    $relativePath,
                    $token,
                    $validated,
                    $checksum,
                ): int {
                    $id = $this->documents->createQueued(new NewDocument(
                        knowledgeBaseId: $knowledgeBaseId,
                        originalFilename: $displayName,
                        storedPath: $relativePath,
                        storageToken: $token,
                        mimeType: $validated->mimeType,
                        extension: $validated->extension,
                        sizeBytes: $validated->sizeBytes,
                        checksumSha256: $checksum,
                        kind: $validated->kind,
                    ));

                    $this->events->record($id, 'queued', 'Uploaded and queued for processing.', [
                        'kind' => $validated->kind->value,
                        'mime' => $validated->mimeType,
                        'size_bytes' => $validated->sizeBytes,
                    ]);

                    return $id;
                });
            } catch (IntegrityException) {
                // A concurrent upload of the same content won the race to the unique index.
                $this->storage->delete($relativePath);
                throw DuplicateDocument::inKnowledgeBase();
            } catch (Throwable $e) {
                $this->storage->delete($relativePath);
                throw $e;
            }
        } finally {
            // No-op once the file has been moved; cleans up on any pre-move failure.
            $this->storage->discard($temporaryAbsolutePath);
        }
    }

    /**
     * The original filename is display-only and always HTML-escaped when rendered, but it is still
     * cleaned here: strip any directory portion (some browsers send a full path), remove control
     * characters and cap the length. Never used to build a filesystem path.
     */
    private function sanitizeDisplayName(string $original, string $extension): string
    {
        // Take the part after the last slash or backslash (some browsers send a full path).
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
