<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\DocumentProcessingParams;
use App\Document\Application\Text\ManualTextService;
use App\Document\Application\Text\PlainTextNormalizer;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\Exception\DocumentLimitReached;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\InvalidText;
use App\Document\Domain\IndexedFileRole;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Storage\StoragePaths;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\Fake\Document\InMemoryTextDocumentRepository;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function bin2hex;
use function file_get_contents;
use function hash;
use function is_dir;
use function random_bytes;
use function scandir;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The manual-text create/edit contract without a database: real storage and normalizer, in-memory
 * repositories. Proves the two properties that matter — an unchanged edit never re-indexes, and a changed
 * edit keeps the old vector-store file until the requeued copy replaces it.
 */
final class ManualTextServiceTest extends Unit
{
    private const KB = 7;

    private string $storageRoot;
    private InMemoryDocumentRepository $documents;
    private InMemoryTextDocumentRepository $texts;
    private InMemoryProcessingEventRepository $events;
    private InMemoryIndexedFileRepository $indexedFiles;
    private LocalDocumentStorage $storage;

    protected function _before(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/kf_manual_' . bin2hex(random_bytes(6));
        $this->documents = new InMemoryDocumentRepository();
        $this->texts = new InMemoryTextDocumentRepository();
        $this->events = new InMemoryProcessingEventRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->storage = new LocalDocumentStorage(
            new StoragePaths($this->storageRoot, $this->storageRoot . '/worker.lock', $this->storageRoot . '/logs'),
        );
    }

    protected function _after(): void
    {
        $this->removeDir($this->storageRoot);
    }

    private function service(int $maxDocuments = 200): ManualTextService
    {
        $params = new DocumentProcessingParams(25 * 1024 * 1024, 8 * 1024 * 1024, $maxDocuments, 12000, 12000);

        return new ManualTextService(
            $this->documents,
            $this->texts,
            $this->events,
            $this->indexedFiles,
            $this->storage,
            new SafeFilenameGenerator(),
            new ImmediateTransactionRunner(),
            $params,
            new MutableClock(),
        );
    }

    public function testCreateStoresNormalizedTextAndQueuesIt(): void
    {
        $id = $this->service()->create(self::KB, 'Refund policy', "Line one\r\n\r\n\r\nLine two   \n");

        assertGreaterThan(0, $id);
        assertSame(1, $this->documents->countLiveForKnowledgeBase(self::KB));
        assertSame(['queued'], $this->events->statuses());

        // The indexed artifact is the deterministic normalized text, not the raw submission.
        $document = $this->documents->findByIdForKnowledgeBase($id, self::KB);
        $stored = (string) file_get_contents($this->storageRoot . '/' . $document?->storedPath());
        assertSame("Line one\n\nLine two\n", $stored);
    }

    public function testCreateRejectsBlankTitle(): void
    {
        $this->expectException(InvalidText::class);

        $this->service()->create(self::KB, '   ', 'Some content');
    }

    public function testCreateRejectsWhitespaceOnlyContent(): void
    {
        try {
            $this->service()->create(self::KB, 'Title', "   \n\n\t");
            $this->fail('Expected InvalidText.');
        } catch (InvalidText) {
            // Nothing must be persisted when the content is empty after normalization.
            assertSame(0, $this->documents->countLiveForKnowledgeBase(self::KB));
            assertCount(0, $this->events->events);
        }
    }

    public function testCreateRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidText::class);

        $this->service()->create(self::KB, 'Title', "bad bytes \xFF\xFE here");
    }

    public function testCreateRejectsDuplicateContentInTheSameKnowledgeBase(): void
    {
        $service = $this->service();
        $service->create(self::KB, 'First', "Shared knowledge\n");

        $this->expectException(DuplicateDocument::class);

        $service->create(self::KB, 'Second', "Shared knowledge\n");
    }

    public function testCreateAllowsTheSameContentInADifferentKnowledgeBase(): void
    {
        $service = $this->service();
        $service->create(self::KB, 'A', "Shared knowledge\n");
        $id = $service->create(self::KB + 1, 'B', "Shared knowledge\n");

        assertSame(1, $this->documents->countLiveForKnowledgeBase(self::KB + 1));
        assertSame(self::KB + 1, $this->documents->findByIdForKnowledgeBase($id, self::KB + 1)?->knowledgeBaseId());
    }

    public function testCreateEnforcesThePerKnowledgeBaseLimit(): void
    {
        $service = $this->service(maxDocuments: 1);
        $service->create(self::KB, 'One', "First\n");

        $this->expectException(DocumentLimitReached::class);

        $service->create(self::KB, 'Two', "Second\n");
    }

    public function testEditingWithUnchangedContentUpdatesMetadataOnlyAndNeverReindexes(): void
    {
        $content = "The body of the note\n";
        $storedPath = $this->storage->derivedMarkdownPath(self::KB, 'seedtoken');
        $this->texts->seed(
            id: 5,
            kbId: self::KB,
            sourceType: DocumentSourceType::ManualText,
            title: 'Original',
            sourceText: $content,
            checksum: hash('sha256', PlainTextNormalizer::normalize($content)),
            storedPath: $storedPath,
        );

        // Same content, only cosmetic (CRLF) differences — normalizes to the same checksum.
        $outcome = $this->service()->update(5, self::KB, 'Renamed', "The body of the note\r\n");

        assertSame(TextUpdateOutcome::Unchanged, $outcome);
        assertSame([5], $this->texts->metadataUpdated);
        assertSame([], $this->texts->replaced);
        assertCount(0, $this->indexedFiles->findPendingRemoval(10), 'an unchanged edit must not schedule removal');
        assertSame(['updated'], $this->events->statuses());
    }

    public function testEditingWithChangedContentReindexesAndKeepsTheOldFileUntilReplaced(): void
    {
        $storedPath = $this->storage->derivedMarkdownPath(self::KB, 'seedtoken');
        $this->storage->putContents($storedPath, "Old body\n");
        $this->texts->seed(
            id: 5,
            kbId: self::KB,
            sourceType: DocumentSourceType::ManualText,
            title: 'Original',
            sourceText: "Old body\n",
            checksum: hash('sha256', "Old body\n"),
            storedPath: $storedPath,
        );
        // An existing indexed file that must survive until the re-index produces its replacement.
        $this->indexedFiles->createPending(5, IndexedFileRole::Source, $storedPath);

        $outcome = $this->service()->update(5, self::KB, 'Original', "New body\n");

        assertSame(TextUpdateOutcome::Reindexed, $outcome);
        assertSame([5], $this->texts->replaced);
        assertSame([], $this->texts->metadataUpdated);
        // The old file is flagged (kept usable until the replacement completes; the cleanup guard's
        // eligibility is covered by CleanupReplacementGuardTest).
        assertSame([5], $this->indexedFiles->pendingRemovalDocumentIds());
        assertSame("New body\n", (string) file_get_contents($this->storageRoot . '/' . $storedPath));
        assertSame(['queued'], $this->events->statuses());
    }

    public function testEditingToContentThatDuplicatesAnotherDocumentIsRejected(): void
    {
        $storedPath = $this->storage->derivedMarkdownPath(self::KB, 'seedtoken');
        $this->texts->seed(
            id: 5,
            kbId: self::KB,
            sourceType: DocumentSourceType::ManualText,
            title: 'Original',
            sourceText: "Old body\n",
            checksum: hash('sha256', "Old body\n"),
            storedPath: $storedPath,
        );
        $this->texts->failReplaceForChecksum(hash('sha256', PlainTextNormalizer::normalize("Taken\n")));

        $this->expectException(DuplicateDocument::class);

        $this->service()->update(5, self::KB, 'Original', "Taken\n");
    }

    public function testEditingAMissingDocumentIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service()->update(999, self::KB, 'Title', 'content');
    }

    public function testEditingANonManualDocumentIsNotFound(): void
    {
        $this->texts->seed(
            id: 5,
            kbId: self::KB,
            sourceType: DocumentSourceType::UploadedText,
            title: 'A file',
            sourceText: '',
            checksum: hash('sha256', "file\n"),
            storedPath: 'kb/7/x.md',
        );

        $this->expectException(NotFoundException::class);

        $this->service()->update(5, self::KB, 'Title', 'content');
    }

    public function testDisabledContentCannotBeEditedAcrossKnowledgeBases(): void
    {
        $this->texts->seed(
            id: 5,
            kbId: self::KB,
            sourceType: DocumentSourceType::ManualText,
            title: 'Original',
            sourceText: "Body\n",
            checksum: hash('sha256', "Body\n"),
            storedPath: 'kb/7/x.md',
        );

        // A different knowledge base id must not resolve the document.
        $this->expectException(NotFoundException::class);

        $this->service()->update(5, self::KB + 1, 'Title', 'content');
    }

    public function testFindListForKnowledgeBaseRoundTripsThroughTheFake(): void
    {
        $this->texts->seed(5, self::KB, DocumentSourceType::ManualText, 'A note', "Body\n", hash('sha256', "Body\n"), 'kb/7/x.md');

        $list = $this->texts->findListForKnowledgeBase(self::KB);

        assertCount(1, $list);
        assertTrue($list[0]->isManualText());
        assertFalse($list[0]->isManualText() && !$list[0]->isEnabled);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $entries */
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
