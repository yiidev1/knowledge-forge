<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Document\Infrastructure\DbDocumentRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessQuery;
use App\Rules\Domain\RuleReadinessStatus;
use App\Rules\Infrastructure\DbRuleReadinessReader;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const STR_PAD_LEFT;

/**
 * Source-grain rule readiness against real MySQL: synced Order58 rules appear even without projections;
 * operational status comes from source activity + global document index snapshot; Ready excludes
 * pending_removal files; card counts match filters.
 */
final class RuleReadinessReaderIntegrationTest extends Unit
{
    private const MARK = 'ZZRDY';
    private const KB_SLUG = 'zzrdy-readiness-kb';
    private const STORE = 970500001;
    private const SOURCE_BASE = 970600000;

    private ConnectionInterface $connection;
    private DbRuleReadinessReader $reader;
    private string $ts;
    private int $kbId;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->reader = new DbRuleReadinessReader($this->connection);
        $now = new DateTimeImmutable('2026-08-06 00:00:00', new DateTimeZone('UTC'));
        $this->ts = DbDateTime::format($now);
        $this->cleanup();

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE, 'name' => 'ZZRDY Store', 'active' => 1, 'sync_hash' => 'h',
            'synced_at' => $this->ts, 'created_at' => $this->ts, 'updated_at' => $this->ts,
        ])->execute();
        $this->kbId = (new DbKnowledgeBaseSourceRepository($this->connection))
            ->createForSource('ZZRDY KB', self::KB_SLUG, 'order58', self::STORE, 'ZZRDY Store', true, $now);

        // Ready: live global doc + completed attached file (documents.status may still be queued).
        $readyCanon = $this->seedSource('ZZRDY Ready', active: true, classification: 'confirmed_common');
        $r1 = $this->doc($readyCanon, 'ZZRDY Ready', 'order58_rule_global', 'queued', true);
        $this->file($r1, 'completed', 'file_ready_1', pendingRemoval: false);

        // Ready despite failed reindex: older completed snapshot still usable.
        $reindexCanon = $this->seedSource('ZZRDY ReadyReindex', active: true, classification: 'confirmed_common');
        $r2 = $this->doc($reindexCanon, 'ZZRDY ReadyReindex', 'order58_rule_global', 'failed', true);
        $this->file($r2, 'completed', 'file_ready_2_old', pendingRemoval: false);
        $this->file($r2, 'failed', null, pendingRemoval: false);

        // Pending-removal completed file must NOT count as Ready → falls through to Failed (doc status failed, no usable file).
        $pendingRemovalCanon = $this->seedSource('ZZRDY PendingRemoval', active: true, classification: 'confirmed_common');
        $pr = $this->doc($pendingRemovalCanon, 'ZZRDY PendingRemoval', 'order58_rule_global', 'failed', true);
        $this->file($pr, 'completed', 'file_pending_removal', pendingRemoval: true);

        // Failed: no usable completed snapshot.
        $failedCanon = $this->seedSource('ZZRDY Failed', active: true, classification: 'confirmed_common');
        $f1 = $this->doc($failedCanon, 'ZZRDY Failed', 'order58_rule_global', 'failed', true);
        $this->file($f1, 'failed', null, pendingRemoval: false);

        // Pending group.
        $idxCanon = $this->seedSource('ZZRDY Indexing', active: true, classification: 'confirmed_common');
        $this->doc($idxCanon, 'ZZRDY Indexing', 'order58_rule_global', 'indexing', true);
        $procCanon = $this->seedSource('ZZRDY Processing', active: true, classification: 'auto_matched');
        $this->doc($procCanon, 'ZZRDY Processing', 'order58_rule_global', 'processing', true);
        $this->linkStore($procCanon);
        $queuedCanon = $this->seedSource('ZZRDY Queued', active: true, classification: 'confirmed_common');
        $this->doc($queuedCanon, 'ZZRDY Queued', 'order58_rule_global', 'queued', true);

        // Disabled live document.
        $disCanon = $this->seedSource('ZZRDY Disabled', active: true, classification: 'confirmed_common');
        $d1 = $this->doc($disCanon, 'ZZRDY Disabled', 'order58_rule_global', 'ready', false);
        $this->file($d1, 'completed', 'file_disabled', pendingRemoval: false);

        // Inactive source, no live projection.
        $this->seedSource('ZZRDY Inactive', active: false, classification: 'confirmed_common');

        // Active source, no projection yet.
        $this->seedSource('ZZRDY NotMaterialized', active: true, classification: 'pending');

        // Soft-deleted projection must not hide the synced source → Not materialized (source still active).
        $goneCanon = $this->seedSource('ZZRDY GoneDoc', active: true, classification: 'confirmed_common');
        $this->doc($goneCanon, 'ZZRDY GoneDoc', 'order58_rule_global', 'deleted', true);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testSyncedSourcesAppearEvenWithoutMaterializedDocuments(): void
    {
        $all = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::All, 1, 50));
        assertGreaterThanOrEqual(11, $all->total, 'every seeded synced source is listed');

        $byTitle = [];
        foreach ($all->items as $item) {
            $byTitle[$item->title] = $item->status;
        }
        assertSame(RuleReadinessStatus::NotMaterialized, $byTitle['ZZRDY NotMaterialized'] ?? null);
        assertSame(RuleReadinessStatus::Inactive, $byTitle['ZZRDY Inactive'] ?? null);
        assertSame(RuleReadinessStatus::NotMaterialized, $byTitle['ZZRDY GoneDoc'] ?? null, 'deleted projection does not hide the source');
    }

    public function testSummaryCountsUseTheSnapshotDerivedOperationalStatus(): void
    {
        $summary = $this->reader->summary(self::MARK);

        assertSame(2, $summary->ready, 'Ready excludes pending_removal snapshots');
        assertSame(2, $summary->failed, 'Failed + PendingRemoval (no usable file)');
        assertSame(1, $summary->indexing);
        assertSame(1, $summary->processing);
        assertSame(1, $summary->queued);
        assertSame(1, $summary->disabled);
        assertSame(1, $summary->inactive);
        assertSame(2, $summary->notMaterialized, 'NotMaterialized + GoneDoc');
        assertSame(3, $summary->pending());
        assertSame(11, $summary->total());
    }

    public function testReadyIsFromTheCompletedSnapshotNotDocumentsStatus(): void
    {
        $items = $this->reader->list(new RuleReadinessQuery('ZZRDY Ready', RuleReadinessFilter::All, 1, 50))->items;
        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item->title] = $item->status;
        }
        assertSame(RuleReadinessStatus::Ready, $byTitle['ZZRDY Ready'] ?? null);
        assertSame(RuleReadinessStatus::Ready, $byTitle['ZZRDY ReadyReindex'] ?? null);
    }

    public function testPendingRemovalFileDoesNotCountAsReady(): void
    {
        $ready = $this->reader->list(new RuleReadinessQuery('ZZRDY PendingRemoval', RuleReadinessFilter::Ready, 1, 50));
        assertSame(0, $ready->total);

        $items = $this->reader->list(new RuleReadinessQuery('ZZRDY PendingRemoval', RuleReadinessFilter::All, 1, 50))->items;
        assertSame(1, count($items));
        assertSame(RuleReadinessStatus::Failed, $items[0]->status);
        assertSame(null, $items[0]->openaiFileId);
    }

    public function testFilterCountsMatchTheTableTotals(): void
    {
        $summary = $this->reader->summary(self::MARK);
        foreach ([
            RuleReadinessFilter::Ready->value => $summary->ready,
            RuleReadinessFilter::Pending->value => $summary->pending(),
            RuleReadinessFilter::Failed->value => $summary->failed,
            RuleReadinessFilter::Disabled->value => $summary->disabledOrInactive(),
            RuleReadinessFilter::NotMaterialized->value => $summary->notMaterialized,
        ] as $filter => $expected) {
            $total = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::from($filter), 1, 50))->total;
            assertSame($expected, $total, "card count matches the $filter filter");
        }
    }

    public function testSearchMatchesSourceIdAndStoreName(): void
    {
        $byStore = $this->reader->list(new RuleReadinessQuery('ZZRDY Store', RuleReadinessFilter::All, 1, 50));
        assertTrue($byStore->total >= 1);
        $found = false;
        foreach ($byStore->items as $item) {
            if ($item->title === 'ZZRDY Processing') {
                $found = true;
                assertSame('ZZRDY Store', $item->storeName);
            }
        }
        assertTrue($found, 'store name search finds the store-linked rule');
    }

    public function testPaginationPreservesFilters(): void
    {
        $page1 = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::All, 1, 3));
        assertSame(11, $page1->total);
        assertSame(4, $page1->pageCount());
        assertSame(3, count($page1->items));

        $page2 = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::All, 2, 3));
        assertSame(11, $page2->total);
        assertSame(3, count($page2->items));
        assertTrue($page1->items[0]->sourceId !== $page2->items[0]->sourceId);
    }

    public function testHiddenBaseScopeOnlyIncludesSourcesWithLiveGlobalDocuments(): void
    {
        $summary = $this->reader->summary(self::MARK, hiddenBaseOnly: true);

        // Live global docs: Ready, ReadyReindex, PendingRemoval(failed), Failed, Indexing, Processing, Queued, Disabled = 8
        // Excludes Inactive / NotMaterialized / GoneDoc (deleted).
        assertSame(2, $summary->ready);
        assertSame(2, $summary->failed);
        assertSame(1, $summary->indexing);
        assertSame(1, $summary->processing);
        assertSame(1, $summary->queued);
        assertSame(1, $summary->disabled);
        assertSame(0, $summary->inactive);
        assertSame(0, $summary->notMaterialized);
        assertSame(8, $summary->total());
    }

    public function testUsableGlobalRuleDocumentIgnoresPendingRemoval(): void
    {
        $docs = new DbDocumentRepository($this->connection, new \App\Shared\Domain\Clock\SystemClock());
        $now = new DateTimeImmutable('2026-08-06 00:00:00', new DateTimeZone('UTC'));
        $ts = DbDateTime::format($now);

        // Dedicated shared-rules KB (may collide with a live one — use a unique slug then update purpose via raw insert).
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'ZZRDY Shared Rules',
            'slug' => 'zzrdy-shared-rules-kb',
            'source_system' => 'system',
            'source_store_id' => null,
            'source_active' => 1,
            'agent_enabled' => 0,
            'purpose' => EnsureCommonRulesKnowledgeBaseService::PURPOSE,
            'vector_store_status' => 'ready',
            'openai_vector_store_id' => 'vs_zzrdy',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
        $sharedId = (int) $this->connection->getLastInsertID();

        $canon = $this->seedSource('ZZRDY ChatGate', active: true, classification: 'confirmed_common');
        $docId = $this->doc($canon, 'ZZRDY ChatGate', 'order58_rule_global', 'ready', true, $sharedId);
        $this->file($docId, 'completed', 'file_chat_gate', pendingRemoval: true);
        assertFalse($docs->hasUsableGlobalRuleDocument($sharedId));

        $this->connection->createCommand()->update(
            '{{%document_index_files}}',
            ['pending_removal' => 0],
            ['document_id' => $docId],
        )->execute();
        assertTrue($docs->hasUsableGlobalRuleDocument($sharedId));
    }

    private function seedSource(string $title, bool $active, string $classification): int
    {
        $sourceId = self::SOURCE_BASE + (++$this->seq);
        $this->connection->createCommand()->insert('{{%order58_rule_records}}', [
            'source_id' => $sourceId,
            'type' => 'Rule',
            'title' => $title,
            'description' => $title . ' body',
            'sync_hash' => str_pad((string) $this->seq, 64, 'a', STR_PAD_LEFT),
            'is_active' => $active ? 1 : 0,
            'synced_at' => $this->ts,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
        $recordId = (int) $this->connection->getLastInsertID();

        $this->connection->createCommand()->insert('{{%rule_catalog_rules}}', [
            'canonical_hash' => hash('sha256', 'zzrdy-canon-' . $sourceId),
            'description_hash' => hash('sha256', 'zzrdy-desc-' . $sourceId),
            'title' => $title,
            'content' => $title . ' body',
            'classification_status' => $classification,
            'is_active' => $active ? 1 : 0,
            'is_globally_available' => 1,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
        $canonicalId = (int) $this->connection->getLastInsertID();

        $this->connection->createCommand()->insert('{{%rule_catalog_sources}}', [
            'rule_catalog_rule_id' => $canonicalId,
            'order58_rule_record_id' => $recordId,
            'relation_type' => 'primary',
            'created_at' => $this->ts,
        ])->execute();

        return $canonicalId;
    }

    private function linkStore(int $canonicalId): void
    {
        $this->connection->createCommand()->insert('{{%rule_store_links}}', [
            'rule_catalog_rule_id' => $canonicalId,
            'store_source_id' => self::STORE,
            'match_status' => 'confirmed',
            'match_method' => 'title_exact_alias',
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
    }

    private function doc(
        int $canonicalId,
        string $title,
        string $sourceType,
        string $status,
        bool $enabled,
        ?int $kbId = null,
    ): int {
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $kbId ?? $this->kbId,
            'original_filename' => 'r.md',
            'stored_path' => 'p/r' . $this->seq . '-' . $canonicalId . '.md',
            'storage_token' => str_pad((string) ($this->seq * 1000 + $canonicalId % 1000), 32, 'z', STR_PAD_LEFT),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_pad((string) ($canonicalId), 64, '0', STR_PAD_LEFT),
            'kind' => 'text',
            'source_type' => $sourceType,
            'source_ref' => (string) $canonicalId,
            'title' => $title,
            'status' => $status,
            'is_enabled' => $enabled ? 1 : 0,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function file(int $documentId, string $indexStatus, ?string $openaiFileId, bool $pendingRemoval): void
    {
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $documentId,
            'role' => 'source',
            'index_status' => $indexStatus,
            'openai_file_id' => $openaiFileId,
            'pending_removal' => $pendingRemoval ? 1 : 0,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
    }

    private function cleanup(): void
    {
        // Raw multi-table deletes — Yii's [[alias]] quoting is unreliable for MySQL JOIN deletes.
        $this->connection->createCommand(
            'DELETE f FROM `document_index_files` f'
            . ' INNER JOIN `documents` d ON d.id = f.document_id'
            . ' WHERE d.title LIKE :mark',
            [':mark' => self::MARK . '%'],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%documents}}', ['like', 'title', self::MARK]);
        IntegrationDb::cleanup($this->connection, '{{%rule_store_links}}', ['store_source_id' => self::STORE]);
        $this->connection->createCommand(
            'DELETE s FROM `rule_catalog_sources` s'
            . ' INNER JOIN `rule_catalog_rules` c ON c.id = s.rule_catalog_rule_id'
            . ' WHERE c.title LIKE :mark',
            [':mark' => self::MARK . '%'],
        )->execute();
        $this->connection->createCommand(
            'DELETE s FROM `rule_catalog_sources` s'
            . ' INNER JOIN `order58_rule_records` r ON r.id = s.order58_rule_record_id'
            . ' WHERE r.source_id BETWEEN :lo AND :hi',
            [':lo' => self::SOURCE_BASE, ':hi' => self::SOURCE_BASE + 500],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%rule_catalog_rules}}', ['like', 'title', self::MARK]);
        $this->connection->createCommand(
            'DELETE FROM `order58_rule_records` WHERE source_id BETWEEN :lo AND :hi OR title LIKE :mark',
            [':lo' => self::SOURCE_BASE, ':hi' => self::SOURCE_BASE + 500, ':mark' => self::MARK . '%'],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => [self::KB_SLUG, 'zzrdy-shared-rules-kb']]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
        $this->seq = 0;
    }
}
