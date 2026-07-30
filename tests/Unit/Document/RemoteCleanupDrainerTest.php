<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\Contract\Exception\AiTimeout;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Document\Application\RemoteCleanupDrainer;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\Tests\Support\Fake\Ai\FakeKnowledgeIndex;
use App\Tests\Support\Fake\Document\InMemoryDocumentProcessingRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * The background remote-cleanup drainer detaches and deletes the OpenAI files left by deleted or
 * re-indexed documents, then drops the local rows. A transient error leaves the row for next time; a
 * permanent one (the file is already gone) drops it anyway, so cleanup never loops forever.
 */
final class RemoteCleanupDrainerTest extends Unit
{
    private const KB = 3;
    private const DOC = 9;
    private const VS = 'vs_ready';

    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryDocumentProcessingRepository $documents;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private FakeKnowledgeIndex $index;

    protected function _before(): void
    {
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->documents = new InMemoryDocumentProcessingRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->index = new FakeKnowledgeIndex();

        $this->knowledgeBases->seedReady(self::KB, 'kb', self::VS);
        $this->documents->seed($this->document());
    }

    public function testRemovesRemoteFileAndDropsTheRow(): void
    {
        $id = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, null);
        $this->indexedFiles->setUploaded($id, 'file_abc', IndexStatus::Completed);
        $this->indexedFiles->markPendingRemovalByDocument(self::DOC);

        $result = $this->drainer()->drain(10);

        assertSame(1, $result->processed);
        assertCount(1, $this->index->removed);
        assertSame('file_abc', $this->index->removed[0]['openaiFileId']);
        assertCount(0, $this->indexedFiles->all());
    }

    public function testTransientErrorLeavesTheRowForNextRun(): void
    {
        $id = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, null);
        $this->indexedFiles->setUploaded($id, 'file_abc', IndexStatus::Completed);
        $this->indexedFiles->markPendingRemovalByDocument(self::DOC);
        $this->index->throwOn('removeFile', new AiTimeout(AiErrorDetails::of('timeout', 'timeout', transient: true)));

        $result = $this->drainer()->drain(10);

        assertSame(1, $result->failed);
        assertCount(1, $this->indexedFiles->all()); // still there, retried later
    }

    public function testPermanentErrorDropsTheRowAnyway(): void
    {
        $id = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, null);
        $this->indexedFiles->setUploaded($id, 'file_gone', IndexStatus::Completed);
        $this->indexedFiles->markPendingRemovalByDocument(self::DOC);
        $this->index->throwOn('removeFile', new AiProcessingFailed(AiErrorDetails::of('not_found', 'already gone', transient: false)));

        $result = $this->drainer()->drain(10);

        assertSame(1, $result->processed);
        assertCount(0, $this->indexedFiles->all());
    }

    public function testRowWithoutRemoteFileIsDroppedWithoutACall(): void
    {
        $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, null);
        $this->indexedFiles->markPendingRemovalByDocument(self::DOC);

        $result = $this->drainer()->drain(10);

        assertSame(1, $result->processed);
        assertCount(0, $this->index->removed);
        assertCount(0, $this->indexedFiles->all());
    }

    private function drainer(): RemoteCleanupDrainer
    {
        return new RemoteCleanupDrainer(
            $this->indexedFiles,
            $this->documents,
            $this->knowledgeBases,
            $this->index,
            new OpenAiCredentials('sk-test', 'https://api.openai.com/v1', 'gpt-x', 'gpt-vision'),
        );
    }

    private function document(): Document
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new Document(
            id: self::DOC,
            knowledgeBaseId: self::KB,
            originalFilename: 'doc.pdf',
            storedPath: 'kb/doc.pdf',
            storageToken: 'tok',
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: 1024,
            checksumSha256: str_repeat('a', 64),
            kind: DocumentKind::Pdf,
            status: DocumentStatus::Deleted,
            processingAttempts: 0,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
