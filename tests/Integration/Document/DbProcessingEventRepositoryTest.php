<?php

declare(strict_types=1);

namespace App\Tests\Integration\Document;

use App\Document\Domain\DocumentKind;
use App\Document\Domain\NewDocument;
use App\Document\Infrastructure\DbDocumentRepository;
use App\Document\Infrastructure\DbProcessingEventRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

/**
 * Exercises the processing-event log against a real database, including JSON metadata round-tripping.
 * Skipped when no database is configured.
 */
final class DbProcessingEventRepositoryTest extends Unit
{
    private const SLUG = '__kf_test_events_kb__';

    private ConnectionInterface $connection;
    private DbProcessingEventRepository $events;
    private int $documentId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $kbId = (new DbKnowledgeBaseRepository($this->connection, new SystemClock()))
            ->create('Events KB', self::SLUG, null, null);
        $this->documentId = (new DbDocumentRepository($this->connection, new SystemClock()))->createQueued(
            new NewDocument($kbId, 'f.pdf', 'p/f.pdf', 'tok', 'application/pdf', 'pdf', 1, 'sum', DocumentKind::Pdf),
        );
        $this->events = new DbProcessingEventRepository($this->connection, new SystemClock());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testRecordsAndReadsBackInOrder(): void
    {
        $this->events->record($this->documentId, 'queued', 'Uploaded and queued.');
        $this->events->record($this->documentId, 'processing', 'Started.');

        $recorded = $this->events->findAllForDocument($this->documentId);
        assertSame(['queued', 'processing'], array_map(static fn($e) => $e->status, $recorded));
        assertSame('Uploaded and queued.', $recorded[0]->message);
    }

    public function testMetadataRoundTripsAsJson(): void
    {
        $this->events->record($this->documentId, 'queued', null, [
            'kind' => 'pdf',
            'size_bytes' => 2048,
        ]);

        $event = $this->events->findAllForDocument($this->documentId)[0];
        assertSame('pdf', $event->metadata['kind'] ?? null);
        assertSame(2048, $event->metadata['size_bytes'] ?? null);
    }

    public function testEmptyMetadataStaysEmpty(): void
    {
        $this->events->record($this->documentId, 'queued');

        assertSame([], $this->events->findAllForDocument($this->documentId)[0]->metadata);
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
