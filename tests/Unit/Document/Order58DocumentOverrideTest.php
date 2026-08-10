<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Order58\UpdateOrder58DocumentService;
use App\Document\Application\Text\PlainTextNormalizer;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Domain\CanonicalDocument;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\Document\Infrastructure\LocalDocumentStorage;
use App\Order58\Application\GeneratedDocumentUpsert;
use App\Order58\Application\SyncDocumentService;
use App\Document\Domain\GeneratedDocument;
use App\Shared\Infrastructure\Storage\StoragePaths;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\MutableClock;
use App\Document\Application\Validation\SafeFilenameGenerator;
use Codeception\Test\Unit;

use function bin2hex;
use function file_get_contents;
use function hash;
use function random_bytes;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

final class Order58DocumentOverrideTest extends Unit
{
    private const KB = 9;

    private string $storageRoot;
    private InMemoryDocumentRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryProcessingEventRepository $events;
    private LocalDocumentStorage $storage;

    /** @var array<int, GeneratedDocument> */
    private array $generated = [];

    protected function _before(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/kf_o58_' . bin2hex(random_bytes(6));
        $this->documents = new InMemoryDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->events = new InMemoryProcessingEventRepository();
        $this->storage = new LocalDocumentStorage(
            new StoragePaths($this->storageRoot, $this->storageRoot . '/worker.lock', $this->storageRoot . '/logs'),
        );
    }

    protected function _after(): void
    {
        $this->removeDir($this->storageRoot);
    }

    public function testSyncSkipsOverriddenDocumentButCreateStillWorks(): void
    {
        $path = $this->storage->derivedMarkdownPath(self::KB, 'tok1');
        $this->storage->putContents($path, "original\n");
        $this->generated[1] = new GeneratedDocument(
            id: 1,
            sourceSyncHash: 'hash-old',
            status: DocumentStatus::Ready->value,
            storedPath: $path,
            storageToken: 'tok1',
            isSourceOverridden: true,
        );

        $sync = new SyncDocumentService(
            new class ($this->generated) implements \App\Document\Domain\GeneratedDocumentRepositoryInterface {
                /** @param array<int, GeneratedDocument> $rows */
                public function __construct(private array &$rows) {}

                public function findBySource(int $knowledgeBaseId, string $sourceType, string $sourceRef): ?GeneratedDocument
                {
                    return $this->rows[1] ?? null;
                }

                public function create(
                    int $knowledgeBaseId,
                    string $sourceType,
                    string $sourceRef,
                    string $title,
                    string $syncHash,
                    string $storedPath,
                    string $storageToken,
                    string $checksum,
                    int $sizeBytes,
                    \DateTimeImmutable $now,
                ): int {
                    return 2;
                }

                public function reindex(
                    int $id,
                    string $title,
                    string $syncHash,
                    string $checksum,
                    int $sizeBytes,
                    \DateTimeImmutable $now,
                ): void {
                    throw new \RuntimeException('reindex must not run for overridden docs');
                }

                public function disable(int $id, \DateTimeImmutable $now): void {}

                public function findLiveLocationsByType(string $sourceType): array
                {
                    return [];
                }
            },
            $this->storage,
            $this->indexedFiles,
            $this->events,
            new SafeFilenameGenerator(),
        );

        $result = $sync->upsertGenerated(
            self::KB,
            DocumentSourceType::Order58StoreProfile,
            '55',
            'Store profile: New Name',
            'hash-new',
            "upstream body\n",
            new \DateTimeImmutable('2026-01-01'),
        );

        assertSame(GeneratedDocumentUpsert::SkippedOverride, $result);
        assertSame("original\n", file_get_contents($this->storage->absolutePath($path)));
        assertSame([], $this->indexedFiles->pendingRemovalDocumentIds());
    }

    public function testOrder58EditSetsOverrideAndRequeuesOnBodyChange(): void
    {
        $path = $this->storage->derivedMarkdownPath(self::KB, 'tok2');
        $original = PlainTextNormalizer::normalize("Store Profile: Old\n");
        $this->storage->putContents($path, $original);
        $checksum = hash('sha256', $original);

        $this->documents->seedCanonical(new CanonicalDocument(
            id: 5,
            knowledgeBaseId: self::KB,
            sourceType: DocumentSourceType::Order58StoreProfile,
            kind: DocumentKind::Text,
            status: DocumentStatus::Ready,
            title: 'Store profile: Old',
            originalFilename: 'Store profile: Old',
            storedPath: $path,
            storageToken: 'tok2',
            mimeType: 'text/markdown',
            extension: 'md',
            sizeBytes: strlen($original),
            checksumSha256: $checksum,
            sourceText: null,
            sourceRef: '55',
            isSourceOverridden: false,
        ));
        $this->indexedFiles->seed(5, IndexedFileRole::Source, 'file-1');

        $service = new UpdateOrder58DocumentService(
            $this->documents,
            $this->storage,
            $this->indexedFiles,
            $this->events,
            new MutableClock(),
        );

        $outcome = $service->update(5, self::KB, 'Store profile: Local', "Local override body\n\nMore text\n");

        assertSame(TextUpdateOutcome::Reindexed, $outcome);
        assertSame([5], $this->documents->overridden);
        assertTrue($this->documents->findCanonicalForKnowledgeBase(5, self::KB)?->isSourceOverridden ?? false);
        assertStringContainsString('Local override body', (string) file_get_contents($this->storage->absolutePath($path)));
        assertSame([5], $this->indexedFiles->pendingRemovalDocumentIds());
    }

    public function testUnchangedOrder58EditDoesNotRequeue(): void
    {
        $path = $this->storage->derivedMarkdownPath(self::KB, 'tok3');
        $body = PlainTextNormalizer::normalize("Same body\n");
        $this->storage->putContents($path, $body);
        $checksum = hash('sha256', $body);

        $this->documents->seedCanonical(new CanonicalDocument(
            id: 6,
            knowledgeBaseId: self::KB,
            sourceType: DocumentSourceType::Order58Knowledge,
            kind: DocumentKind::Text,
            status: DocumentStatus::Ready,
            title: 'Knowledge A',
            originalFilename: 'Knowledge A',
            storedPath: $path,
            storageToken: 'tok3',
            mimeType: 'text/markdown',
            extension: 'md',
            sizeBytes: strlen($body),
            checksumSha256: $checksum,
            sourceText: null,
            sourceRef: '99',
            isSourceOverridden: false,
        ));

        $service = new UpdateOrder58DocumentService(
            $this->documents,
            $this->storage,
            $this->indexedFiles,
            $this->events,
            new MutableClock(),
        );

        $outcome = $service->update(6, self::KB, 'Knowledge A', $body);

        assertSame(TextUpdateOutcome::Unchanged, $outcome);
        assertSame([], $this->documents->overridden);
        assertFalse($this->documents->findCanonicalForKnowledgeBase(6, self::KB)?->isSourceOverridden ?? true);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
