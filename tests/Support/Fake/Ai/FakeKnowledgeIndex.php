<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\Contract\Dto\IndexedFileResult;
use App\Ai\Contract\Dto\IndexState;
use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\KnowledgeIndexInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Scriptable {@see KnowledgeIndexInterface} for worker tests. Records the calls made, hands back file
 * ids and states the test dictates, and can be told to throw a specific {@see AiException} on the next
 * call to a given method — enough to drive every branch of the processing state machine without a
 * network.
 */
final class FakeKnowledgeIndex implements KnowledgeIndexInterface
{
    /** @var list<array{vectorStoreId: string, filename: string, attributes: array<string, string>}> */
    public array $indexed = [];

    /** @var list<array{vectorStoreId: string, openaiFileId: string}> */
    public array $removed = [];

    /** @var list<string> */
    public array $createdStores = [];

    private int $nextFileNumber = 1;

    private int $nextStoreNumber = 1;

    private IndexState $stateAfterIndex;

    /** @var array<string, IndexState> Overrides keyed by file id, consumed by fileState(). */
    private array $fileStates = [];

    /** @var array<string, AiException> Pending throws keyed by method name. */
    private array $throwOn = [];

    public function __construct()
    {
        $this->stateAfterIndex = new IndexState(IndexStatus::Completed);
    }

    public function throwOn(string $method, AiException $exception): void
    {
        $this->throwOn[$method] = $exception;
    }

    /** State returned by indexContent(), and the default returned by fileState(). */
    public function setStateAfterIndex(IndexState $state): void
    {
        $this->stateAfterIndex = $state;
    }

    public function setFileState(string $openaiFileId, IndexState $state): void
    {
        $this->fileStates[$openaiFileId] = $state;
    }

    public function createStore(string $name, array $metadata): string
    {
        $this->maybeThrow('createStore');
        $id = 'vs_fake_' . $this->nextStoreNumber++;
        $this->createdStores[] = $id;

        return $id;
    }

    public function deleteStore(string $vectorStoreId): void
    {
        $this->maybeThrow('deleteStore');
    }

    public function indexContent(string $vectorStoreId, StreamInterface $content, string $filename, array $attributes): IndexedFileResult
    {
        $this->maybeThrow('indexContent');
        $fileId = 'file_fake_' . $this->nextFileNumber++;
        $this->indexed[] = ['vectorStoreId' => $vectorStoreId, 'filename' => $filename, 'attributes' => $attributes];

        return new IndexedFileResult($fileId, $this->stateAfterIndex);
    }

    public function fileState(string $vectorStoreId, string $openaiFileId): IndexState
    {
        $this->maybeThrow('fileState');

        return $this->fileStates[$openaiFileId] ?? $this->stateAfterIndex;
    }

    public function removeFile(string $vectorStoreId, string $openaiFileId): void
    {
        $this->maybeThrow('removeFile');
        $this->removed[] = ['vectorStoreId' => $vectorStoreId, 'openaiFileId' => $openaiFileId];
    }

    private function maybeThrow(string $method): void
    {
        $exception = $this->throwOn[$method] ?? null;
        if ($exception !== null) {
            unset($this->throwOn[$method]);

            throw $exception;
        }
    }
}
