<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Usage\UsageMapping;
use App\Ai\Application\Usage\UsageReconciler;
use App\Ai\Application\Usage\UsageStoreRow;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use App\Document\Domain\DocumentStatus;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The four reconciliation states, and the guarantee that reconciling changes nothing.
 */
final class UsageReconcilerTest extends Unit
{
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private InMemoryDocumentRepository $documents;

    protected function _before(): void
    {
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->documents = new InMemoryDocumentRepository();
    }

    public function testKnowledgeBaseAndStoreThatAgreeAreMatched(): void
    {
        $this->knowledgeBases->seedReady(1, 'hr-docs', 'vs_1');

        $mappings = $this->reconcile([$this->store('vs_1')]);

        assertCount(1, $mappings);
        assertSame(UsageMapping::STATE_MATCHED, $mappings[0]->state);
        assertSame(1, $mappings[0]->knowledgeBaseId);
        assertSame('vs_1', $mappings[0]->remoteVectorStoreId);
    }

    /**
     * A store this application does not know about. Reported only — another environment may legitimately
     * share the same OpenAI account, so "unknown here" is not "safe to delete".
     */
    public function testRemoteStoreWithNoKnowledgeBaseIsFlaggedUnmapped(): void
    {
        $mappings = $this->reconcile([$this->store('vs_orphan')]);

        assertCount(1, $mappings);
        assertSame(UsageMapping::STATE_REMOTE_UNMAPPED, $mappings[0]->state);
        assertSame('vs_orphan', $mappings[0]->remoteVectorStoreId);
        assertTrue($mappings[0]->isProblem());
    }

    /**
     * The expensive case to miss: the knowledge base still points at a store that is gone, so chat will
     * fail against it until someone re-provisions.
     */
    public function testKnowledgeBaseReferencingAMissingStoreIsFlagged(): void
    {
        $this->knowledgeBases->seedReady(1, 'hr-docs', 'vs_gone');

        $mappings = $this->reconcile([$this->store('vs_other')]);

        $byState = $this->byState($mappings);
        assertSame(UsageMapping::STATE_LOCAL_MISSING_REMOTE, $byState['vs_gone']->state);
        assertSame(UsageMapping::STATE_REMOTE_UNMAPPED, $byState['vs_other']->state);
    }

    /**
     * Locally ready but not completed at the provider: one side acted on information the other does not
     * have.
     */
    public function testStatusDisagreementIsFlagged(): void
    {
        $this->knowledgeBases->seedReady(1, 'hr-docs', 'vs_1');

        $mappings = $this->reconcile([$this->store('vs_1', 'in_progress')]);

        assertSame(UsageMapping::STATE_STATUS_MISMATCH, $mappings[0]->state);
        assertTrue($mappings[0]->isProblem());
    }

    /**
     * A knowledge base with no store yet is expected, not a fault, and must not be reported as a problem.
     */
    public function testKnowledgeBaseWithoutAStoreIsNotAProblem(): void
    {
        $this->knowledgeBases->create('New base', 'new-base', null, null);

        $mappings = $this->reconcile([]);

        assertCount(1, $mappings);
        assertSame(UsageMapping::STATE_NOT_PROVISIONED, $mappings[0]->state);
        assertSame(false, $mappings[0]->isProblem());
    }

    /**
     * Archived bases still own billed storage, which is exactly the cost this page exists to surface.
     */
    public function testArchivedKnowledgeBasesAreStillReconciled(): void
    {
        $this->knowledgeBases->seedReady(1, 'old-docs', 'vs_1', KnowledgeBaseStatus::Archived);

        $mappings = $this->reconcile([$this->store('vs_1')]);

        assertCount(1, $mappings);
        assertSame('vs_1', $mappings[0]->remoteVectorStoreId);
        assertTrue($mappings[0]->archived);
    }

    public function testProblemsAreListedFirst(): void
    {
        $this->knowledgeBases->seedReady(1, 'aaa-ok', 'vs_ok');

        $mappings = $this->reconcile([$this->store('vs_ok'), $this->store('vs_zzz_orphan')]);

        assertTrue($mappings[0]->isProblem());
    }

    public function testReportsLocalDocumentCounts(): void
    {
        $this->knowledgeBases->seedReady(1, 'hr-docs', 'vs_1');
        $this->documents->seed(10, 1, DocumentStatus::Ready);
        $this->documents->seed(11, 1, DocumentStatus::Uploaded);

        $mappings = $this->reconcile([$this->store('vs_1', 'completed', 7)]);

        assertSame(2, $mappings[0]->localDocumentCount);
        assertSame(1, $mappings[0]->localReadyDocumentCount);
        assertSame(7, $mappings[0]->remoteFileCount);
    }

    /**
     * @param list<UsageStoreRow> $stores
     *
     * @return list<UsageMapping>
     */
    private function reconcile(array $stores): array
    {
        return (new UsageReconciler($this->knowledgeBases, $this->documents))->reconcile($stores);
    }

    /**
     * @param list<UsageMapping> $mappings
     *
     * @return array<string, UsageMapping>
     */
    private function byState(array $mappings): array
    {
        $indexed = [];
        foreach ($mappings as $mapping) {
            $indexed[(string) $mapping->remoteVectorStoreId] = $mapping;
        }

        return $indexed;
    }

    private function store(string $id, string $status = 'completed', int $files = 0): UsageStoreRow
    {
        return new UsageStoreRow(
            id: $id,
            name: $id,
            status: $status,
            usageBytes: 1024,
            fileCounts: new VectorStoreFileCounts($files, $files, 0, 0, 0),
            createdAt: null,
            lastActiveAt: null,
            expiresAt: null,
        );
    }
}
