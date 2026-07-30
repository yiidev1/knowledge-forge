<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\Contract\Exception\AiException;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiFile;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFilePage;
use App\Ai\OpenAi\Dto\OpenAiVectorStorePage;
use Psr\Http\Message\StreamInterface;

use function array_shift;

/**
 * A programmable {@see OpenAiClientInterface} for adapter and reconciler tests. Each method returns a
 * sensible default unless a test overrides it, and every call is logged by name.
 */
final class FakeOpenAiClient implements OpenAiClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<OpenAiResponse|AiException> */
    public array $responseQueue = [];

    /**
     * Every request handed to createResponse(), so a test can assert what actually went on the wire —
     * the tool configuration, tool_choice and reasoning controls, not just the reply.
     *
     * @var list<OpenAiResponseRequest>
     */
    public array $responseRequests = [];

    public ?OpenAiFile $uploadFileReturn = null;
    public ?OpenAiVectorStoreFile $attachReturn = null;

    /** @var list<OpenAiVectorStore> */
    public array $vectorStores = [];

    /**
     * Pages returned in order by listVectorStorePage(), so a multi-page sweep can actually be tested.
     * Seeding $vectorStores alone yields one final page, which would let a broken pagination loop pass.
     *
     * @var list<OpenAiVectorStorePage>
     */
    public array $vectorStorePages = [];

    /** @var list<OpenAiVectorStoreFilePage> */
    public array $filePages = [];

    /** @var array<string, list<OpenAiVectorStoreFile>> */
    public array $vectorStoreFiles = [];

    /**
     * Every `after` cursor the client was called with, so a test can prove the sweep followed the
     * cursor rather than requesting page one repeatedly.
     *
     * @var list<?string>
     */
    public array $vectorStorePageCursors = [];

    /** Set to make the inventory call fail — the "OpenAI unreachable" path. */
    public ?AiException $vectorStoreFailure = null;

    /** Set to make per-store file listing fail while the inventory still succeeds. */
    public ?AiException $fileFailure = null;

    /**
     * Invoked at the start of every listVectorStorePage() call. Lets a test advance a MutableClock so
     * a time budget can be exercised deterministically, without sleeping.
     *
     * @var ?callable(): void
     */
    public $onListVectorStorePage = null;

    public function uploadFile(StreamInterface $content, string $filename, string $purpose, ?string $idempotencyKey = null): OpenAiFile
    {
        $this->calls[] = 'uploadFile';

        return $this->uploadFileReturn ?? new OpenAiFile('file-default', $filename, $purpose, 0, 0);
    }

    public function listFiles(string $purpose, int $limit = 100, ?string $after = null): array
    {
        $this->calls[] = 'listFiles';

        return [];
    }

    public function deleteFile(string $fileId): bool
    {
        $this->calls[] = 'deleteFile';

        return true;
    }

    public function createVectorStore(string $name, array $metadata = [], ?string $idempotencyKey = null): OpenAiVectorStore
    {
        $this->calls[] = 'createVectorStore';

        return new OpenAiVectorStore('vs-default', $name, 'completed', $metadata, 0);
    }

    public function listVectorStores(int $limit = 100, ?string $after = null): array
    {
        $this->calls[] = 'listVectorStores';

        return $this->vectorStores;
    }

    public function listVectorStorePage(int $limit = 100, ?string $after = null): OpenAiVectorStorePage
    {
        $this->calls[] = 'listVectorStorePage';
        $this->vectorStorePageCursors[] = $after;

        if ($this->onListVectorStorePage !== null) {
            ($this->onListVectorStorePage)();
        }

        if ($this->vectorStoreFailure !== null) {
            throw $this->vectorStoreFailure;
        }

        // A queue of pages lets a test drive a real multi-page sweep; without one, everything seeded in
        // $vectorStores comes back as a single, final page.
        if ($this->vectorStorePages !== []) {
            $page = array_shift($this->vectorStorePages);

            return $page;
        }

        return new OpenAiVectorStorePage($this->vectorStores);
    }

    public function getVectorStore(string $vectorStoreId): OpenAiVectorStore
    {
        $this->calls[] = 'getVectorStore';

        foreach ($this->vectorStores as $store) {
            if ($store->id === $vectorStoreId) {
                return $store;
            }
        }

        return new OpenAiVectorStore($vectorStoreId, 'fake', 'completed', [], 0);
    }

    public function listVectorStoreFilePage(
        string $vectorStoreId,
        int $limit = 100,
        ?string $after = null,
        ?string $filter = null,
    ): OpenAiVectorStoreFilePage {
        $this->calls[] = 'listVectorStoreFilePage';

        if ($this->fileFailure !== null) {
            throw $this->fileFailure;
        }

        if ($this->filePages !== []) {
            return array_shift($this->filePages);
        }

        return new OpenAiVectorStoreFilePage($this->vectorStoreFiles[$vectorStoreId] ?? []);
    }

    public function deleteVectorStore(string $vectorStoreId): bool
    {
        $this->calls[] = 'deleteVectorStore';

        return true;
    }

    public function attachFileToVectorStore(string $vectorStoreId, string $fileId, array $attributes = [], ?string $idempotencyKey = null): OpenAiVectorStoreFile
    {
        $this->calls[] = 'attachFileToVectorStore';

        return $this->attachReturn ?? new OpenAiVectorStoreFile($fileId, $vectorStoreId, 'in_progress', null, null, null);
    }

    public function getVectorStoreFile(string $vectorStoreId, string $fileId): OpenAiVectorStoreFile
    {
        $this->calls[] = 'getVectorStoreFile';

        return new OpenAiVectorStoreFile($fileId, $vectorStoreId, 'completed', null, null, 100);
    }

    public function detachFileFromVectorStore(string $vectorStoreId, string $fileId): bool
    {
        $this->calls[] = 'detachFileFromVectorStore';

        return true;
    }

    public function createResponse(OpenAiResponseRequest $request): OpenAiResponse
    {
        $this->calls[] = 'createResponse';
        $this->responseRequests[] = $request;

        $next = array_shift($this->responseQueue);
        if ($next instanceof AiException) {
            throw $next;
        }

        return $next ?? new OpenAiResponse('resp-default', 'completed', 'OK', false, null, 0, 0.0, [], \App\Ai\Contract\Dto\TokenUsage::zero(), $request->model);
    }
}
