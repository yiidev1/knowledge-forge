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
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\CapturingLogger;
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

use function count;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

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
    private CapturingLogger $logger;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentProcessingRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->index = new FakeKnowledgeIndex();
        $this->clock = new MutableClock();
        $this->logger = new CapturingLogger();

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
            $this->logger,
            new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
        );
    }

    /**
     * The readiness checks are a safety net behind the SQL filter. When one fires it must say so, using
     * only ids and a fixed reason — never a filename, a stored path, document content or a credential.
     */
    public function testSkippedDocumentIsLoggedWithSafeFieldsOnly(): void
    {
        // Knowledge base left unprovisioned, so the safety net fires.
        $this->drainer()->drain(5);

        $logged = $this->logger->everything();

        assertStringContainsString('document processing skipped', $logged);
        assertStringContainsString('knowledge_base_not_ready', $logged);
        assertStringContainsString('"document_id":' . self::DOC, $logged);

        // Nothing identifying or sensitive may appear.
        assertStringNotContainsString('sk-test', $logged);
        assertStringNotContainsString('stored_path', $logged);
        assertStringNotContainsString('.txt', $logged);
    }

    /**
     * The ordering rule this pins: a document already uploaded and waiting to be polled is picked before
     * fresh queued work, even though there is only one slot per run.
     *
     * Without it, never-scheduled documents (next_attempt_at NULL, which MySQL sorts FIRST in ASC)
     * monopolise every run, indexing documents are never polled, and nothing reaches `ready`.
     */
    public function testDueIndexingDocumentIsPolledBeforeFreshQueuedWork(): void
    {
        $repository = new InMemoryDocumentProcessingRepository();
        // Fresh queued work with the LOWER id, so id-ordering alone would pick it first.
        $repository->seed($this->documentWith(1, DocumentStatus::Queued));
        // An indexing document with a HIGHER id whose poll is already due.
        $repository->seed(
            $this->documentWith(2, DocumentStatus::Indexing),
            $this->clock->now()->modify('-10 seconds'),
        );

        $picked = $repository->findProcessable(1, $this->clock->now());

        assertSame(1, count($picked));
        assertSame(2, $picked[0]->id(), 'the due indexing document must win the single slot');
    }

    /**
     * The complement, and what stops this trading one starvation for its mirror image: when no poll is
     * due, fresh queued work proceeds as normal.
     */
    public function testFreshQueuedWorkResumesWhenNoPollIsDue(): void
    {
        $repository = new InMemoryDocumentProcessingRepository();
        $repository->seed($this->documentWith(1, DocumentStatus::Queued));
        $repository->seed(
            $this->documentWith(2, DocumentStatus::Indexing),
            $this->clock->now()->modify('+5 minutes'),
        );

        $picked = $repository->findProcessable(1, $this->clock->now());

        assertSame(1, count($picked));
        assertSame(1, $picked[0]->id(), 'with no due poll, queued work proceeds');
    }

    /**
     * A retry scheduled after a transient failure is also work already begun, so it outranks fresh
     * queued work once its backoff has elapsed.
     */
    public function testDueRetryOutranksFreshQueuedWork(): void
    {
        $repository = new InMemoryDocumentProcessingRepository();
        $repository->seed($this->documentWith(1, DocumentStatus::Queued));
        $repository->seed(
            $this->documentWith(2, DocumentStatus::Queued),
            $this->clock->now()->modify('-1 second'),
        );

        $picked = $repository->findProcessable(1, $this->clock->now());

        assertSame(2, $picked[0]->id(), 'the due retry must be picked before never-scheduled work');
    }

    private function documentWith(int $id, DocumentStatus $status): Document
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new Document(
            id: $id,
            knowledgeBaseId: self::KB,
            originalFilename: 'doc.txt',
            storedPath: 'kb/doc.txt',
            storageToken: 'tok' . $id,
            mimeType: 'text/plain',
            extension: 'txt',
            sizeBytes: 64,
            checksumSha256: str_repeat((string) $id, 64),
            kind: DocumentKind::Text,
            status: $status,
            processingAttempts: 0,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $now,
            updatedAt: $now,
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
