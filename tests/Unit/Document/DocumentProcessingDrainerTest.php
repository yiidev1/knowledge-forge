<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Application\Processing\DocumentProcessingDrainer;
use App\Document\Application\Processing\DocumentProcessorRegistry;
use App\Document\Application\Processing\ProcessDocumentService;
use App\Document\Application\Processing\RecoverStuckDocumentsService;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\FakeKnowledgeIndex;
use App\Tests\Support\Fake\Document\InMemoryDocumentProcessingRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\Fake\Document\StubDocumentProcessor;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\MutableClock;
use App\Worker\Application\WorkerParams;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

/**
 * The document-processing drainer end to end: a queued document in a ready knowledge base is driven to
 * `ready` through the real processing service against the fake adapter; a document whose knowledge base
 * is still provisioning is left untouched, burning no attempt; and a claimed document cannot be claimed
 * twice, so two workers never process the same one.
 */
final class DocumentProcessingDrainerTest extends Unit
{
    private const KB = 1;
    private const DOC = 1;
    private const VS = 'vs_ready';

    private InMemoryDocumentProcessingRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private FakeKnowledgeIndex $index;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentProcessingRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->index = new FakeKnowledgeIndex();
        $this->clock = new MutableClock();

        $this->documents->seed($this->queuedDocument());
    }

    public function testQueuedDocumentInReadyKnowledgeBaseReachesReady(): void
    {
        $this->knowledgeBases->seedReady(self::KB, 'kb', self::VS);

        $result = $this->drainer()->drain(5);

        assertSame(1, $result->processed);
        assertSame(DocumentStatus::Ready, $this->documents->statusOf(self::DOC));
        assertSame(1, $this->documents->attemptsOf(self::DOC)); // one attempt spent
    }

    public function testDocumentWaitingOnProvisioningIsLeftUntouched(): void
    {
        // A pending (not-yet-provisioned) knowledge base: create() defaults to pending with no store id.
        $this->knowledgeBases->create('KB', 'kb', null, null);

        $result = $this->drainer()->drain(5);

        assertSame(0, $result->processed);
        assertSame(DocumentStatus::Queued, $this->documents->statusOf(self::DOC));
        assertSame(0, $this->documents->attemptsOf(self::DOC)); // NOT claimed → no attempt burned
    }

    public function testAClaimedDocumentCannotBeClaimedAgain(): void
    {
        $now = $this->clock->now();

        assertSame(true, $this->documents->claim(self::DOC, DocumentStatus::Queued, $now));
        // A second worker attempting the same transition loses.
        assertFalse($this->documents->claim(self::DOC, DocumentStatus::Queued, $now));
    }

    private function drainer(): DocumentProcessingDrainer
    {
        $params = new WorkerParams(
            batchSize: 1,
            maxProcessingAttempts: 3,
            processingTimeoutMinutes: 20,
            retryBaseSeconds: 60,
            provisionMaxAttempts: 5,
            indexPollIntervalSeconds: 3,
        );

        $service = new ProcessDocumentService(
            $this->documents,
            $this->indexedFiles,
            $this->index,
            new DocumentProcessorRegistry([new StubDocumentProcessor()]),
            new InMemoryProcessingEventRepository(),
            $this->clock,
            new SecretRedactor(),
            $params,
        );

        return new DocumentProcessingDrainer(
            $this->documents,
            $this->knowledgeBases,
            $service,
            new RecoverStuckDocumentsService($this->documents, $this->clock, $params),
            new OpenAiCredentials('sk-test', 'https://api.openai.com/v1', 'gpt-x', 'gpt-vision'),
            $this->clock,
        );
    }

    private function queuedDocument(): Document
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
            status: DocumentStatus::Queued,
            processingAttempts: 0,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
