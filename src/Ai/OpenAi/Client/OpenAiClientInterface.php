<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

use App\Ai\Contract\Exception\AiException;
use App\Ai\OpenAi\Dto\OpenAiFile;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFilePage;
use App\Ai\OpenAi\Dto\OpenAiVectorStorePage;
use Psr\Http\Message\StreamInterface;

/**
 * The typed gateway over the OpenAI REST endpoints the application uses.
 *
 * Nothing above this interface sees raw OpenAI JSON, HTTP, or retry logic. Two instances exist in the
 * container — one per timeout/retry profile (chat vs worker) — so this interface is also the seam at
 * which tests substitute a fake client and make zero network calls.
 *
 * The `$idempotencyKey` on the create/upload methods is sent as a best-effort header but is never relied
 * upon for correctness; the operation ledger provides the real guarantee against duplicates.
 */
interface OpenAiClientInterface
{
    /**
     * @throws AiException
     */
    public function uploadFile(
        StreamInterface $content,
        string $filename,
        string $purpose,
        ?string $idempotencyKey = null,
    ): OpenAiFile;

    /**
     * @return list<OpenAiFile>
     *
     * @throws AiException
     */
    public function listFiles(string $purpose, int $limit = 100, ?string $after = null): array;

    /**
     * @throws AiException
     */
    public function deleteFile(string $fileId): bool;

    /**
     * @param array<string, string> $metadata
     *
     * @throws AiException
     */
    public function createVectorStore(string $name, array $metadata = [], ?string $idempotencyKey = null): OpenAiVectorStore;

    /**
     * The first page of vector stores, without the cursor envelope.
     *
     * Convenient for a lookup, but it is only ever the FIRST page — anything that must reason about the
     * whole account has to use {@see listVectorStorePage()} instead, or it silently draws conclusions
     * from the first hundred stores.
     *
     * @return list<OpenAiVectorStore>
     *
     * @throws AiException
     */
    public function listVectorStores(int $limit = 100, ?string $after = null): array;

    /**
     * One page of vector stores, keeping the `has_more`/`last_id` cursor so a caller can sweep the
     * whole account.
     *
     * @throws AiException
     */
    public function listVectorStorePage(int $limit = 100, ?string $after = null): OpenAiVectorStorePage;

    /**
     * A single vector store by id. Read-only.
     *
     * Lets a caller tell "deleted at the provider" apart from "beyond the page cap" when a locally
     * stored id did not appear in a truncated sweep. A store that no longer exists surfaces as
     * {@see \App\Ai\Contract\Exception\AiProcessingFailed} with an HTTP 404 in its details.
     *
     * @throws AiException
     */
    public function getVectorStore(string $vectorStoreId): OpenAiVectorStore;

    /**
     * One page of the files attached to a vector store. Metadata only — file content is never fetched.
     *
     * @param ?string $filter One of `in_progress`, `completed`, `failed`, `cancelled`; null for all.
     *
     * @throws AiException
     */
    public function listVectorStoreFilePage(
        string $vectorStoreId,
        int $limit = 100,
        ?string $after = null,
        ?string $filter = null,
    ): OpenAiVectorStoreFilePage;

    /**
     * @throws AiException
     */
    public function deleteVectorStore(string $vectorStoreId): bool;

    /**
     * @param array<string, string> $attributes
     *
     * @throws AiException
     */
    public function attachFileToVectorStore(
        string $vectorStoreId,
        string $fileId,
        array $attributes = [],
        ?string $idempotencyKey = null,
    ): OpenAiVectorStoreFile;

    /**
     * @throws AiException
     */
    public function getVectorStoreFile(string $vectorStoreId, string $fileId): OpenAiVectorStoreFile;

    /**
     * @throws AiException
     */
    public function detachFileFromVectorStore(string $vectorStoreId, string $fileId): bool;

    /**
     * @throws AiException
     */
    public function createResponse(OpenAiResponseRequest $request): OpenAiResponse;
}
