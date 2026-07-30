<?php

declare(strict_types=1);

namespace App\Tests\Integration\Document;

use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\NewDocument;
use App\Document\Infrastructure\DbDocumentRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Exercises the document repository against a real database, including the generated-column dedupe
 * index that guarantees no live duplicate and yet allows re-upload after deletion. Skipped when no
 * database is configured.
 */
final class DbDocumentRepositoryTest extends Unit
{
    private const SLUG = '__kf_test_docs_kb__';

    private ConnectionInterface $connection;
    private DbDocumentRepository $repository;
    private int $kbId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->kbId = (new DbKnowledgeBaseRepository($this->connection, new SystemClock()))
            ->create('Docs KB', self::SLUG, null, null);
        $this->repository = new DbDocumentRepository($this->connection, new SystemClock());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function newDocument(string $checksum, string $token = 'tok', string $name = 'file.pdf'): NewDocument
    {
        return new NewDocument(
            knowledgeBaseId: $this->kbId,
            originalFilename: $name,
            storedPath: 'knowledge-bases/' . $this->kbId . '/documents/' . $token . '.pdf',
            storageToken: $token,
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: 1234,
            checksumSha256: $checksum,
            kind: DocumentKind::Pdf,
        );
    }

    public function testCreateQueuedRoundTrips(): void
    {
        $id = $this->repository->createQueued($this->newDocument('a1'));

        $document = $this->repository->findByIdForKnowledgeBase($id, $this->kbId);
        assertSame('file.pdf', $document?->originalFilename());
        assertSame(DocumentStatus::Queued, $document?->status());
        assertSame(DocumentKind::Pdf, $document?->kind());
        assertSame(1234, $document?->sizeBytes());
    }

    public function testScopedLookupRejectsForeignKnowledgeBase(): void
    {
        $id = $this->repository->createQueued($this->newDocument('a2'));

        assertNull($this->repository->findByIdForKnowledgeBase($id, $this->kbId + 99999));
    }

    public function testLiveChecksumExistsAndCount(): void
    {
        assertFalse($this->repository->liveChecksumExists('dup', $this->kbId));

        $this->repository->createQueued($this->newDocument('dup', 'tok1'));

        assertTrue($this->repository->liveChecksumExists('dup', $this->kbId));
        assertSame(1, $this->repository->countLiveForKnowledgeBase($this->kbId));
    }

    /**
     * The unique dedupe index — not just the application pre-check — must reject a live duplicate.
     */
    public function testUniqueIndexRejectsLiveDuplicate(): void
    {
        $this->repository->createQueued($this->newDocument('same', 'tokA'));

        $this->expectException(IntegrityException::class);

        $this->repository->createQueued($this->newDocument('same', 'tokB'));
    }

    /**
     * After a soft delete the dedupe slot is freed (the generated column becomes NULL), so the same
     * content can be uploaded again.
     */
    public function testReuploadAllowedAfterSoftDelete(): void
    {
        $id = $this->repository->createQueued($this->newDocument('reup', 'tokA'));
        $this->repository->markDeleted($id);

        // Must not throw now.
        $newId = $this->repository->createQueued($this->newDocument('reup', 'tokB'));

        assertSame(1, $this->repository->countLiveForKnowledgeBase($this->kbId));
        assertTrue($newId > 0);
    }

    public function testDeletedDocumentsAreHiddenFromListings(): void
    {
        $id = $this->repository->createQueued($this->newDocument('h1', 'tokA'));
        $this->repository->createQueued($this->newDocument('h2', 'tokB'));

        $this->repository->markDeleted($id);

        assertCount(1, $this->repository->findAllForKnowledgeBase($this->kbId));
        assertNull($this->repository->findByIdForKnowledgeBase($id, $this->kbId));
    }

    private function cleanup(): void
    {
        // Documents cascade with the knowledge base.
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
