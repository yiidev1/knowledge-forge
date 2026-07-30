<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Adapter;

use App\Ai\Contract\Dto\IndexedFileResult;
use App\Ai\Contract\Dto\IndexState;
use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiInvalidResponse;
use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\KnowledgeIndexInterface;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use Psr\Http\Message\StreamInterface;

/**
 * Implements the knowledge-index port over the OpenAI Files + Vector Stores APIs.
 *
 * `indexContent` performs the two-step upload-then-attach. It asserts that the vector-store file id the
 * API returns equals the uploaded file id — the application relies on these being one value (a single
 * `openai_file_id` column) — and raises an invalid-response error if OpenAI ever diverges, rather than
 * silently storing a mismatched id.
 */
final readonly class OpenAiKnowledgeIndex implements KnowledgeIndexInterface
{
    private const UPLOAD_PURPOSE = 'assistants';

    public function __construct(
        private OpenAiClientInterface $client,
    ) {}

    public function createStore(string $name, array $metadata): string
    {
        return $this->client->createVectorStore($name, $metadata)->id;
    }

    public function deleteStore(string $vectorStoreId): void
    {
        $this->client->deleteVectorStore($vectorStoreId);
    }

    public function indexContent(
        string $vectorStoreId,
        StreamInterface $content,
        string $filename,
        array $attributes,
    ): IndexedFileResult {
        $file = $this->client->uploadFile($content, $filename, self::UPLOAD_PURPOSE);
        $vectorStoreFile = $this->client->attachFileToVectorStore($vectorStoreId, $file->id, $attributes);

        if ($vectorStoreFile->id !== $file->id) {
            // The whole citation model assumes these ids are identical; refuse to persist a mismatch.
            throw new AiInvalidResponse(AiErrorDetails::of(
                code: 'file_id_mismatch',
                safeMessage: 'OpenAI returned a vector-store file id that does not match the uploaded file id.',
            ));
        }

        return new IndexedFileResult($file->id, $this->toState($vectorStoreFile));
    }

    public function fileState(string $vectorStoreId, string $openaiFileId): IndexState
    {
        return $this->toState($this->client->getVectorStoreFile($vectorStoreId, $openaiFileId));
    }

    public function removeFile(string $vectorStoreId, string $openaiFileId): void
    {
        // Detach from the store first, then delete the underlying file. A failure to delete the file
        // after detaching leaves an orphan file (swept later), not a dangling store reference.
        $this->client->detachFileFromVectorStore($vectorStoreId, $openaiFileId);
        $this->client->deleteFile($openaiFileId);
    }

    private function toState(OpenAiVectorStoreFile $file): IndexState
    {
        return new IndexState(
            status: $this->mapStatus($file->status),
            errorCode: $file->lastErrorCode,
            errorMessage: $file->lastErrorMessage,
            usageBytes: $file->usageBytes,
        );
    }

    private function mapStatus(string $status): IndexStatus
    {
        return match ($status) {
            'completed' => IndexStatus::Completed,
            'in_progress' => IndexStatus::InProgress,
            'failed' => IndexStatus::Failed,
            'cancelled' => IndexStatus::Cancelled,
            default => IndexStatus::Pending,
        };
    }
}
