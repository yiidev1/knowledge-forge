<?php

declare(strict_types=1);

namespace App\Ai\Contract;

use App\Ai\Contract\Dto\IndexedFileResult;
use App\Ai\Contract\Dto\IndexState;
use App\Ai\Contract\Exception\AiException;
use Psr\Http\Message\StreamInterface;

/**
 * Manages a knowledge base's hosted vector store: provisioning it, adding and removing files, and
 * checking indexing progress.
 *
 * Used only by the background worker. The application persists the ids returned here (vector store id,
 * file id) and polls {@see fileState()} across worker runs rather than blocking on indexing.
 */
interface KnowledgeIndexInterface
{
    /**
     * @param array<string, string> $metadata Provider-side metadata, used later to reconcile a create
     *                                         whose response was lost (e.g. the knowledge-base id).
     *
     * @return string The new vector store id.
     *
     * @throws AiException
     */
    public function createStore(string $name, array $metadata): string;

    /**
     * @throws AiException
     */
    public function deleteStore(string $vectorStoreId): void;

    /**
     * Uploads content and attaches it to the vector store in one logical step.
     *
     * @param array<string, string> $attributes Provider-side attributes stored on the vector-store file
     *                                           (e.g. document id), so retrieval results are
     *                                           self-identifying.
     *
     * @throws AiException
     */
    public function indexContent(
        string $vectorStoreId,
        StreamInterface $content,
        string $filename,
        array $attributes,
    ): IndexedFileResult;

    /**
     * Current indexing state of a file, for polling to completion.
     *
     * @throws AiException
     */
    public function fileState(string $vectorStoreId, string $openaiFileId): IndexState;

    /**
     * Detaches a file from the vector store and deletes the underlying uploaded file.
     *
     * @throws AiException
     */
    public function removeFile(string $vectorStoreId, string $openaiFileId): void;
}
