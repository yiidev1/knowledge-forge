<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Application\ProcessNowService;
use App\Document\Application\ReindexDocumentService;
use App\Document\Application\RetryDocumentService;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\Exception\DocumentActionNotAllowed;
use App\Document\Domain\IndexedFileRole;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The enqueue-only operator actions: retry, re-index and process-now. Each mutates local state and
 * returns immediately — no OpenAI — and each guards its allowed source status server-side.
 */
final class DocumentActionsTest extends Unit
{
    private const KB = 2;
    private const DOC = 5;

    private InMemoryDocumentRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryProcessingEventRepository $events;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->events = new InMemoryProcessingEventRepository();
    }

    public function testRetryRequeuesAFailedDocumentAndFlagsOldFilesForRemoval(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Failed);
        $this->giveIndexedFile();

        $this->retryService()->retry(self::KB, self::DOC);

        assertSame(DocumentStatus::Queued, $this->documents->status(self::DOC));
        assertCount(1, $this->indexedFiles->findPendingRemoval(10));
    }

    public function testRetryRejectsANonFailedDocument(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);

        $this->expectException(DocumentActionNotAllowed::class);
        $this->retryService()->retry(self::KB, self::DOC);
    }

    public function testReindexRequeuesAReadyDocumentAndFlagsOldFilesForRemoval(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $this->giveIndexedFile();

        $this->reindexService()->reindex(self::KB, self::DOC);

        assertSame(DocumentStatus::Queued, $this->documents->status(self::DOC));
        assertCount(1, $this->indexedFiles->findPendingRemoval(10));
    }

    public function testReindexRejectsANonReadyDocument(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Failed);

        $this->expectException(DocumentActionNotAllowed::class);
        $this->reindexService()->reindex(self::KB, self::DOC);
    }

    public function testProcessNowPrioritisesAnInProgressDocument(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Queued);

        $this->processNowService()->processNow(self::KB, self::DOC);

        assertTrue($this->documents->wasPrioritised(self::DOC));
    }

    public function testProcessNowRejectsATerminalDocument(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);

        $this->expectException(DocumentActionNotAllowed::class);
        $this->processNowService()->processNow(self::KB, self::DOC);
    }

    private function giveIndexedFile(): void
    {
        $id = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, null);
        $this->indexedFiles->setUploaded($id, 'file_old', IndexStatus::Completed);
    }

    private function retryService(): RetryDocumentService
    {
        return new RetryDocumentService($this->documents, $this->indexedFiles, $this->events);
    }

    private function reindexService(): ReindexDocumentService
    {
        return new ReindexDocumentService($this->documents, $this->indexedFiles, $this->events);
    }

    private function processNowService(): ProcessNowService
    {
        return new ProcessNowService($this->documents, $this->events);
    }
}
