<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Dto\IndexState;
use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\Contract\Exception\AiTimeout;
use App\Document\Application\Processing\DocumentProcessorRegistry;
use App\Document\Application\Processing\ProcessDocumentService;
use App\Document\Application\Processing\ProcessingOutcome;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\FakeKnowledgeIndex;
use App\Tests\Support\Fake\Document\InMemoryDocumentProcessingRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\Fake\Document\StubDocumentProcessor;
use App\Tests\Support\MutableClock;
use App\Worker\Application\WorkerParams;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function count;
use function str_repeat;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

/**
 * The non-blocking ingestion state machine, exercised with in-memory repositories, a scriptable
 * knowledge index and a stub processor — so every branch (ready, defer-and-poll, transient requeue,
 * attempt-cap fail, manual-review fail, provider index failure) is proven without a network.
 */
final class ProcessDocumentServiceTest extends Unit
{
    private const VS = 'vs_test';
    private const KB = 4;

    private InMemoryDocumentProcessingRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryProcessingEventRepository $events;
    private FakeKnowledgeIndex $index;
    private StubDocumentProcessor $processor;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentProcessingRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->events = new InMemoryProcessingEventRepository();
        $this->index = new FakeKnowledgeIndex();
        $this->processor = new StubDocumentProcessor();
        $this->clock = new MutableClock();
    }

    public function testQueuedDocumentReachesReadyInOneRunWhenIndexingCompletes(): void
    {
        $document = $this->claimedDocument(1, attempts: 1);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Ready, $outcome);
        assertSame(DocumentStatus::Ready, $this->documents->statusOf(1));
        assertCount(1, $this->index->indexed);
        assertSame('1', $this->index->indexed[0]['attributes']['document_id']);
    }

    public function testIndexingInProgressDefersToNextRunThenCompletes(): void
    {
        $this->index->setStateAfterIndex(new IndexState(IndexStatus::InProgress));

        $document = $this->claimedDocument(1, attempts: 1);
        $first = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Indexing, $first);
        assertSame(DocumentStatus::Indexing, $this->documents->statusOf(1));

        // Second run: the file has finished indexing; only a poll happens, no re-upload.
        $this->index->setFileState('file_fake_1', new IndexState(IndexStatus::Completed));

        $resumed = $this->documents->find(1);
        self::assertNotNull($resumed);
        $second = $this->service()->process($resumed, self::VS);

        assertSame(ProcessingOutcome::Ready, $second);
        assertCount(1, $this->index->indexed); // still one upload — no duplicate
    }

    public function testTransientFailureRequeuesWithBackoffAndClearsPartialIndexFiles(): void
    {
        $this->index->throwOn('indexContent', new AiTimeout(AiErrorDetails::of('timeout', 'read timeout', transient: true)));

        $document = $this->claimedDocument(1, attempts: 1);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Requeued, $outcome);
        assertSame(DocumentStatus::Queued, $this->documents->statusOf(1));
        // Backoff scheduled into the future.
        $next = $this->documents->nextAttemptAtOf(1);
        self::assertNotNull($next);
        assertGreaterThan($this->clock->now(), $next);
        // The partial attempt left no index-file rows behind.
        assertCount(0, $this->indexedFiles->all());
    }

    public function testTransientFailureAtAttemptCapFailsPermanently(): void
    {
        $this->index->throwOn('indexContent', new AiTimeout(AiErrorDetails::of('timeout', 'read timeout', transient: true)));

        // maxProcessingAttempts=3, and this is the third attempt → no more retries.
        $document = $this->claimedDocument(1, attempts: 3);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Failed, $outcome);
        assertSame(DocumentStatus::Failed, $this->documents->statusOf(1));
    }

    public function testManualReviewFailsWithoutRetry(): void
    {
        $this->processor->willRequireManualReview('Split the PDF and re-upload.');

        $document = $this->claimedDocument(1, attempts: 1);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Failed, $outcome);
        assertSame(DocumentStatus::Failed, $this->documents->statusOf(1));
        assertSame(0, $this->processorProducedUploads());
    }

    public function testUnrecoverableProviderErrorDuringExtractionFailsWithoutRetry(): void
    {
        $this->processor->willThrow(new AiProcessingFailed(AiErrorDetails::of('unsupported', 'bad input', transient: false)));

        $document = $this->claimedDocument(1, attempts: 1);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Failed, $outcome);
        assertSame(DocumentStatus::Failed, $this->documents->statusOf(1));
    }

    public function testProviderIndexFailureFailsTheDocument(): void
    {
        $this->index->setStateAfterIndex(new IndexState(IndexStatus::Failed, 'unsupported_file', 'Unsupported file.'));

        $document = $this->claimedDocument(1, attempts: 1);
        $outcome = $this->service()->process($document, self::VS);

        assertSame(ProcessingOutcome::Failed, $outcome);
        assertSame(DocumentStatus::Failed, $this->documents->statusOf(1));
    }

    private function service(): ProcessDocumentService
    {
        return new ProcessDocumentService(
            $this->documents,
            $this->indexedFiles,
            $this->index,
            new DocumentProcessorRegistry([$this->processor]),
            $this->events,
            $this->clock,
            new SecretRedactor(),
            $this->params(),
        );
    }

    private function params(): WorkerParams
    {
        return new WorkerParams(
            batchSize: 1,
            maxProcessingAttempts: 3,
            processingTimeoutMinutes: 20,
            retryBaseSeconds: 60,
            provisionMaxAttempts: 5,
            indexPollIntervalSeconds: 3,
        );
    }

    private function claimedDocument(int $id, int $attempts): Document
    {
        $document = new Document(
            id: $id,
            knowledgeBaseId: self::KB,
            originalFilename: 'doc.pdf',
            storedPath: 'kb/doc.pdf',
            storageToken: 'tok' . $id,
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: 1024,
            checksumSha256: str_repeat('a', 64),
            kind: DocumentKind::Pdf,
            status: DocumentStatus::Processing,
            processingAttempts: $attempts,
            errorCode: null,
            errorMessage: null,
            processedAt: null,
            createdAt: $this->at(),
            updatedAt: $this->at(),
        );
        $this->documents->seed($document);

        return $document;
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    private function processorProducedUploads(): int
    {
        return count($this->index->indexed);
    }
}
