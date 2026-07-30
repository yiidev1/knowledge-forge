<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Usage\CollectUsageSnapshotService;
use App\Ai\Application\Usage\SyncProblem;
use App\Ai\Application\Usage\UsageCalculator;
use App\Ai\Application\Usage\UsageParams;
use App\Ai\Application\Usage\UsageReconciler;
use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Exception\AiAuthenticationFailed;
use App\Ai\Contract\Exception\AiTransportFailed;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFilePage;
use App\Ai\OpenAi\Dto\OpenAiVectorStorePage;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use App\Tests\Support\Fake\Ai\FakeOpenAiClient;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function array_filter;
use function count;
use function in_array;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The sync orchestration: pagination, the time budget, and degrading rather than failing.
 */
final class CollectUsageSnapshotServiceTest extends Unit
{
    private FakeOpenAiClient $client;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private InMemoryDocumentRepository $documents;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->client = new FakeOpenAiClient();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->clock = new MutableClock();
    }

    /**
     * More than one page of stores must all be collected, and the sweep must FOLLOW the cursor rather
     * than asking for page one repeatedly.
     */
    public function testSweepsEveryPageFollowingTheCursor(): void
    {
        $this->client->vectorStorePages = [
            new OpenAiVectorStorePage($this->stores('a', 100), hasMore: true, lastId: 'cursor-1'),
            new OpenAiVectorStorePage($this->stores('b', 40), hasMore: false, lastId: 'cursor-2'),
        ];

        $snapshot = $this->service()->collect();

        assertCount(140, $snapshot->stores);
        assertFalse($snapshot->truncated);
        // First call has no cursor; the second must carry the first page's last_id.
        assertSame([null, 'cursor-1'], $this->client->vectorStorePageCursors);
    }

    /**
     * A provider that keeps claiming there is more must not spin the loop forever. The page cap stops
     * it and the result is honestly labelled partial.
     */
    public function testEndlessPaginationIsStoppedAndMarkedTruncated(): void
    {
        $pages = [];
        for ($i = 0; $i < 40; $i++) {
            $pages[] = new OpenAiVectorStorePage($this->stores('p' . $i, 1), hasMore: true, lastId: 'cursor-' . $i);
        }
        $this->client->vectorStorePages = $pages;

        $snapshot = $this->service()->collect();

        assertTrue($snapshot->truncated);
        // Capped at MAX_STORE_PAGES, not 40.
        assertSame(10, $this->pageCalls());
    }

    /**
     * A repeated cursor would loop forever if the guard were missing.
     */
    public function testRepeatedCursorStopsTheSweep(): void
    {
        $this->client->vectorStorePages = [
            new OpenAiVectorStorePage($this->stores('a', 1), hasMore: true, lastId: 'same'),
            new OpenAiVectorStorePage($this->stores('b', 1), hasMore: true, lastId: 'same'),
            new OpenAiVectorStorePage($this->stores('c', 1), hasMore: true, lastId: 'same'),
        ];

        $snapshot = $this->service()->collect();

        assertCount(2, $snapshot->stores);
    }

    /**
     * Correction 2: the budget is checked BEFORE each call, so a slow provider cannot make the sweep
     * outlast the web request. Time is driven by the clock, not by sleeping.
     *
     * The contract is "do not START a call once the budget is spent", so one call that consumes the
     * whole budget is enough to stop the next one. That is what bounds the worst case at
     * budget + one in-flight call, rather than at budget alone.
     */
    public function testDoesNotStartACallOnceTheBudgetIsSpent(): void
    {
        $clock = $this->clock;
        $this->client->vectorStorePages = [
            new OpenAiVectorStorePage($this->stores('a', 5), hasMore: true, lastId: 'cursor-1'),
            new OpenAiVectorStorePage($this->stores('b', 5), hasMore: true, lastId: 'cursor-2'),
        ];

        // One call consumes more than the whole budget, so no second call may begin.
        $this->client->onListVectorStorePage = static function () use ($clock): void {
            $clock->advance('+50 seconds');
        };

        $snapshot = $this->service(budgetSeconds: 45)->collect();

        assertTrue($snapshot->truncated);
        assertCount(5, $snapshot->stores);
        assertSame(1, $this->pageCalls());
    }

    /**
     * The complement: while budget remains, the sweep keeps going. Two 30s calls are both permitted
     * against a 45s budget (the second starts at 30s elapsed), and the third is refused.
     */
    public function testKeepsSweepingWhileBudgetRemains(): void
    {
        $clock = $this->clock;
        $this->client->vectorStorePages = [
            new OpenAiVectorStorePage($this->stores('a', 5), hasMore: true, lastId: 'cursor-1'),
            new OpenAiVectorStorePage($this->stores('b', 5), hasMore: true, lastId: 'cursor-2'),
            new OpenAiVectorStorePage($this->stores('c', 5), hasMore: true, lastId: 'cursor-3'),
        ];

        $this->client->onListVectorStorePage = static function () use ($clock): void {
            $clock->advance('+30 seconds');
        };

        $snapshot = $this->service(budgetSeconds: 45)->collect();

        assertSame(2, $this->pageCalls());
        assertCount(10, $snapshot->stores);
        assertTrue($snapshot->truncated);
    }

    /**
     * Losing the inventory is the one failure that leaves nothing to show. It must surface as a named
     * problem, not an exception escaping to a 500.
     */
    public function testInventoryFailureIsReportedAsAProblem(): void
    {
        $this->client->vectorStoreFailure = new AiTransportFailed(
            AiErrorDetails::of('server_error', 'The OpenAI API is unavailable.', 503),
        );

        $snapshot = $this->service()->collect();

        assertSame([], $snapshot->stores);
        assertCount(1, $snapshot->problems);
        assertSame(SyncProblem::SOURCE_VECTOR_STORES, $snapshot->problems[0]->source);
        assertTrue($snapshot->truncated);
    }

    /**
     * One store's file list failing must cost only that store's detail. The counts come from the store
     * object itself, so every total stays correct.
     */
    public function testFileListFailureDegradesOnlyThatStoresDetail(): void
    {
        $this->client->vectorStores = [
            new OpenAiVectorStore('vs_1', 'kf-1', 'completed', [], 1785000000, 4096, new VectorStoreFileCounts(3, 3, 0, 0, 0)),
        ];
        $this->client->fileFailure = new AiAuthenticationFailed(
            AiErrorDetails::of('auth_failed', 'The OpenAI API rejected the credentials.', 401),
        );

        $snapshot = $this->service()->collect();

        assertCount(1, $snapshot->stores);
        assertSame(4096, $snapshot->totals->totalUsageBytes);
        assertSame(3, $snapshot->totals->fileCounts->total);
        assertNotSame(null, $snapshot->stores[0]->fileDetailProblem);
        assertSame(SyncProblem::SOURCE_VECTOR_STORE_FILES, $snapshot->problems[0]->source);
        assertSame('vs_1', $snapshot->problems[0]->subject);
    }

    public function testCollectsFileDetailForEachStore(): void
    {
        $this->client->vectorStores = [
            new OpenAiVectorStore('vs_1', 'kf-1', 'completed', [], 1785000000, 4096, new VectorStoreFileCounts(1, 1, 0, 0, 0)),
        ];
        $this->client->filePages = [
            new OpenAiVectorStoreFilePage([
                new OpenAiVectorStoreFile('file-1', 'vs_1', 'completed', null, null, 512, 1785000001, 'auto'),
            ]),
        ];

        $snapshot = $this->service()->collect();

        assertCount(1, $snapshot->stores[0]->files);
        assertSame('file-1', $snapshot->stores[0]->files[0]->id);
        assertSame(512, $snapshot->stores[0]->files[0]->usageBytes);
    }

    /**
     * Nothing in a sync may mutate anything at the provider.
     */
    public function testUsesOnlyReadOnlyClientMethods(): void
    {
        $this->client->vectorStores = [
            new OpenAiVectorStore('vs_1', 'kf-1', 'completed', [], 1785000000, 10),
        ];

        $this->service()->collect();

        $allowed = ['listVectorStorePage', 'getVectorStore', 'listVectorStoreFilePage'];
        foreach ($this->client->calls as $call) {
            assertTrue(in_array($call, $allowed, true), 'Unexpected client call: ' . $call);
        }
    }

    private function pageCalls(): int
    {
        return count(array_filter($this->client->calls, static fn(string $c): bool => $c === 'listVectorStorePage'));
    }

    /**
     * @return list<OpenAiVectorStore>
     */
    private function stores(string $prefix, int $count): array
    {
        $stores = [];
        for ($i = 0; $i < $count; $i++) {
            $stores[] = new OpenAiVectorStore(
                'vs_' . $prefix . $i,
                'kf-' . $prefix . $i,
                'completed',
                [],
                1785000000,
                1024,
            );
        }

        return $stores;
    }

    private function service(int $budgetSeconds = 45): CollectUsageSnapshotService
    {
        return new CollectUsageSnapshotService(
            $this->client,
            new UsageReconciler($this->knowledgeBases, $this->documents),
            new UsageCalculator(),
            $this->clock,
            new UsageParams(budgetSeconds: $budgetSeconds),
        );
    }
}
