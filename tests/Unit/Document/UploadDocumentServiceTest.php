<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\DocumentProcessingParams;
use App\Document\Application\UploadDocumentService;
use App\Document\Application\Validation\ImageInspector;
use App\Document\Application\Validation\MimeTypeDetector;
use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Application\Validation\UploadValidator;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\Exception\DocumentLimitReached;
use App\Document\Domain\Exception\DuplicateDocument;
use App\Document\Domain\Exception\UnsupportedDocumentType;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\Shared\Infrastructure\Storage\StoragePaths;
use App\Tests\Support\DocumentFixtures;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use Codeception\Test\Unit;

use function sys_get_temp_dir;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Exercises the whole upload pipeline minus HTTP and the database: real validator, real local storage
 * (in a temp root), in-memory repository and event log. This is where "a valid upload becomes a stored,
 * queued document, and a bad one leaves nothing behind" is proven.
 */
final class UploadDocumentServiceTest extends Unit
{
    private const KB = 7;

    private DocumentFixtures $fixtures;
    private string $storageRoot;
    private InMemoryDocumentRepository $documents;
    private InMemoryProcessingEventRepository $events;
    private LocalDocumentStorage $storage;

    protected function _before(): void
    {
        $this->fixtures = new DocumentFixtures();
        $this->storageRoot = sys_get_temp_dir() . '/kf_storage_' . bin2hex(random_bytes(6));
        $this->documents = new InMemoryDocumentRepository();
        $this->events = new InMemoryProcessingEventRepository();
        $this->storage = new LocalDocumentStorage(
            new StoragePaths($this->storageRoot, $this->storageRoot . '/worker.lock', $this->storageRoot . '/logs'),
        );
    }

    protected function _after(): void
    {
        $this->fixtures->cleanup();
        $this->removeDir($this->storageRoot);
    }

    private function service(int $maxDocuments = 200): UploadDocumentService
    {
        $params = new DocumentProcessingParams(25 * 1024 * 1024, 8 * 1024 * 1024, $maxDocuments, 12000, 12000);

        return new UploadDocumentService(
            $this->documents,
            $this->events,
            $this->storage,
            new UploadValidator(new MimeTypeDetector(), new ImageInspector(), $params),
            new SafeFilenameGenerator(),
            new ImmediateTransactionRunner(),
            $params,
        );
    }

    /**
     * The caller always hands the service a temporary file to consume; simulate that by copying a
     * fixture into a temp file the storage would have created.
     */
    private function capture(string $fixturePath): string
    {
        $temp = $this->storage->createTemporaryFile();
        copy($fixturePath, $temp);

        return $temp;
    }

    public function testValidPdfBecomesAQueuedStoredDocument(): void
    {
        $id = $this->service()->upload(self::KB, 'My Report.pdf', $this->capture($this->fixtures->pdf()));

        $document = $this->documents->findByIdForKnowledgeBase($id, self::KB);
        assertSame('My Report.pdf', $document?->originalFilename());
        assertSame(DocumentKind::Pdf, $document?->kind());

        // The file exists in storage, and the stored name contains none of the original filename.
        assertFileExists($this->storageRoot . '/' . $document?->storedPath());
        assertStringNotContainsString('Report', (string) $document?->storedPath());

        // A queued event was recorded.
        assertSame(['queued'], $this->events->statuses());
    }

    public function testValidImageIsClassifiedAsImage(): void
    {
        $id = $this->service()->upload(self::KB, 'scan.png', $this->capture($this->fixtures->png()));

        assertSame(DocumentKind::Image, $this->documents->findByIdForKnowledgeBase($id, self::KB)?->kind());
    }

    /**
     * A rejected upload must leave nothing: no row, no event, and no temporary or stored file.
     */
    public function testRejectedUploadLeavesNothingBehind(): void
    {
        $temp = $this->capture($this->fixtures->phpDisguisedAsPdf());

        try {
            $this->service()->upload(self::KB, 'evil.pdf', $temp);
            $this->fail('Expected UnsupportedDocumentType.');
        } catch (UnsupportedDocumentType) {
            // expected
        }

        assertSame(0, $this->documents->countLiveForKnowledgeBase(self::KB));
        assertCount(0, $this->events->events);
        assertFileDoesNotExist($temp, 'the temporary file must be discarded');
    }

    public function testDuplicateContentIsRejected(): void
    {
        $service = $this->service();
        $service->upload(self::KB, 'first.pdf', $this->capture($this->fixtures->pdf('same')));

        $this->expectException(DuplicateDocument::class);

        $service->upload(self::KB, 'second.pdf', $this->capture($this->fixtures->pdf('same')));
    }

    public function testSameContentInDifferentKnowledgeBasesIsAllowed(): void
    {
        $service = $this->service();
        $service->upload(self::KB, 'a.pdf', $this->capture($this->fixtures->pdf('shared')));
        $id = $service->upload(self::KB + 1, 'a.pdf', $this->capture($this->fixtures->pdf('shared')));

        assertSame(1, $this->documents->countLiveForKnowledgeBase(self::KB + 1));
        assertSame(self::KB + 1, $this->documents->findByIdForKnowledgeBase($id, self::KB + 1)?->knowledgeBaseId());
    }

    public function testPerKnowledgeBaseLimitIsEnforced(): void
    {
        $service = $this->service(maxDocuments: 1);
        $service->upload(self::KB, 'one.pdf', $this->capture($this->fixtures->pdf('one')));

        $this->expectException(DocumentLimitReached::class);

        $service->upload(self::KB, 'two.pdf', $this->capture($this->fixtures->pdf('two')));
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
