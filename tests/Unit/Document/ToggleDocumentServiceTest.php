<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\ToggleDocumentService;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\Shared\Domain\Exception\NotFoundException;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\Fake\Document\InMemoryTextDocumentRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * Enable/disable applies to every source type. Disabling hides a document from retrieval and schedules its
 * vector-store file for removal without deleting the row; enabling requeues it. Both are scoped to the
 * knowledge base, so an id from another base is a 404.
 */
final class ToggleDocumentServiceTest extends Unit
{
    private const KB = 7;
    private const DOC = 5;

    private InMemoryDocumentRepository $documents;
    private InMemoryTextDocumentRepository $texts;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryProcessingEventRepository $events;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentRepository();
        $this->texts = new InMemoryTextDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->events = new InMemoryProcessingEventRepository();
    }

    private function service(): ToggleDocumentService
    {
        return new ToggleDocumentService(
            $this->documents,
            $this->texts,
            $this->indexedFiles,
            $this->events,
            new MutableClock(),
        );
    }

    public function testDisablingFlagsTheIndexFileForRemovalAndRecordsAnEvent(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $this->indexedFiles->createPending(self::DOC, IndexedFileRole::Source, 'kb/7/x.md');

        $this->service()->setEnabled(self::DOC, self::KB, false);

        assertSame([['id' => self::DOC, 'enabled' => false]], $this->texts->enabledCalls);
        // The file is flagged for removal; a disabled document's file is eligible for cleanup — that
        // eligibility is verified against the real query in CleanupReplacementGuardTest.
        assertSame([self::DOC], $this->indexedFiles->pendingRemovalDocumentIds());
        assertSame(['disabled'], $this->events->statuses());
        // The row is preserved, not requeued.
        assertSame(DocumentStatus::Ready, $this->documents->status(self::DOC));
    }

    public function testEnablingRequeuesTheDocumentAndRecordsAnEvent(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Failed);

        $this->service()->setEnabled(self::DOC, self::KB, true);

        assertSame([['id' => self::DOC, 'enabled' => true]], $this->texts->enabledCalls);
        assertSame(DocumentStatus::Queued, $this->documents->status(self::DOC));
        assertCount(0, $this->indexedFiles->findPendingRemoval(10));
        assertSame(['queued'], $this->events->statuses());
    }

    public function testTogglingAMissingDocumentIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service()->setEnabled(999, self::KB, false);
    }

    public function testTogglingIsScopedToTheKnowledgeBase(): void
    {
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);

        $this->expectException(NotFoundException::class);

        $this->service()->setEnabled(self::DOC, self::KB + 1, false);
    }
}
