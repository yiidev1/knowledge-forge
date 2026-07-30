<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiInvalidResponse;
use App\Ai\OpenAi\Adapter\OpenAiKnowledgeIndex;
use App\Ai\OpenAi\Dto\OpenAiFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Tests\Support\Fake\Ai\FakeOpenAiClient;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\Utils;

use function PHPUnit\Framework\assertSame;

final class OpenAiKnowledgeIndexTest extends Unit
{
    private FakeOpenAiClient $client;
    private OpenAiKnowledgeIndex $index;

    protected function _before(): void
    {
        $this->client = new FakeOpenAiClient();
        $this->index = new OpenAiKnowledgeIndex($this->client);
    }

    public function testIndexContentUploadsThenAttachesAndReturnsTheFileId(): void
    {
        $this->client->uploadFileReturn = new OpenAiFile('file-123', 'a.pdf', 'assistants', 10, 0);
        $this->client->attachReturn = new OpenAiVectorStoreFile('file-123', 'vs-1', 'in_progress', null, null, null);

        $result = $this->index->indexContent('vs-1', Utils::streamFor('data'), 'a.pdf', ['document_id' => '5']);

        assertSame('file-123', $result->openaiFileId);
        assertSame(IndexStatus::InProgress, $result->state->status);
        assertSame(['uploadFile', 'attachFileToVectorStore'], $this->client->calls);
    }

    /**
     * The application stores one id for both the uploaded file and the vector-store file. If OpenAI ever
     * returns a different id on attach, that assumption is broken and the adapter refuses to proceed.
     */
    public function testMismatchedVectorStoreFileIdRaisesInvalidResponse(): void
    {
        $this->client->uploadFileReturn = new OpenAiFile('file-123', 'a.pdf', 'assistants', 10, 0);
        $this->client->attachReturn = new OpenAiVectorStoreFile('file-DIFFERENT', 'vs-1', 'in_progress', null, null, null);

        $this->expectException(AiInvalidResponse::class);

        $this->index->indexContent('vs-1', Utils::streamFor('data'), 'a.pdf', []);
    }

    public function testRemoveFileDetachesThenDeletes(): void
    {
        $this->index->removeFile('vs-1', 'file-1');

        assertSame(['detachFileFromVectorStore', 'deleteFile'], $this->client->calls);
    }

    public function testFileStateMapsStatus(): void
    {
        // FakeOpenAiClient.getVectorStoreFile returns status 'completed'.
        assertSame(IndexStatus::Completed, $this->index->fileState('vs-1', 'file-1')->status);
    }
}
