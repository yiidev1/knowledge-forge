<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessQuery;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Domain\RuleReadinessStatus;
use App\Rules\Infrastructure\DbRuleReadinessReader;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const STR_PAD_LEFT;

/**
 * The readiness read model against real MySQL. Operational status comes from the durable completed index-file
 * snapshot (never from documents.status alone): a completed+attached file is Ready even while a reindex is queued
 * or failed, Failed excludes anything still Ready, Pending groups Queued/Processing/Indexing, only rule source
 * types are included, and the card counts equal the filtered rows. Scoped to sentinel titles ("ZZRDY").
 */
final class RuleReadinessReaderIntegrationTest extends Unit
{
    private const MARK = 'ZZRDY';
    private const KB_SLUG = 'zzrdy-readiness-kb';
    private const STORE = 970500001;

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

        // Ready: a store doc whose documents.status is 'queued' but which HAS a completed attached file.
        $r1 = $this->doc('ZZRDY Ready', 'order58_rule_store', 'queued', true);
        $this->file($r1, 'completed', 'file_ready_1');
        // Ready despite a queued/failed reindex: an OLDER completed snapshot is still searchable.
        $r2 = $this->doc('ZZRDY ReadyReindex', 'order58_rule_global', 'failed', true);
        $this->file($r2, 'completed', 'file_ready_2_old');
        $this->file($r2, 'failed', null);
        // Failed: no completed snapshot, and the latest attempt failed.
        $f1 = $this->doc('ZZRDY Failed', 'order58_rule_store', 'failed', true);
        $this->file($f1, 'failed', null);
        // Pending group: indexing / processing / queued (no completed snapshot).
        $this->doc('ZZRDY Indexing', 'order58_rule_global', 'indexing', true);
        $this->doc('ZZRDY Processing', 'order58_rule_store', 'processing', true);
        $this->doc('ZZRDY Queued', 'order58_rule_global', 'queued', true);
        // Disabled: is_enabled = 0 wins even with a completed snapshot.
        $d1 = $this->doc('ZZRDY Disabled', 'order58_rule_store', 'ready', false);
        $this->file($d1, 'completed', 'file_disabled');
        // Excluded: a non-rule document, and a soft-deleted rule document.
        $this->doc('ZZRDY Knowledge', 'order58_knowledge', 'ready', true);
        $this->doc('ZZRDY Gone', 'order58_rule_store', 'deleted', true);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testSummaryCountsUseTheSnapshotDerivedOperationalStatus(): void
    {
        $summary = $this->reader->summary(self::MARK);

        assertSame(2, $summary->ready, 'both completed-snapshot docs are Ready (even the one whose status is failed/queued)');
        assertSame(1, $summary->failed);
        assertSame(1, $summary->indexing);
        assertSame(1, $summary->processing);
        assertSame(1, $summary->queued);
        assertSame(1, $summary->disabled);
        assertSame(3, $summary->pending(), 'pending groups queued + processing + indexing');
        assertSame(7, $summary->total(), 'non-rule and deleted docs are excluded');
    }

    public function testReadyIsFromTheCompletedSnapshotNotDocumentsStatus(): void
    {
        $items = $this->reader->list(new RuleReadinessQuery('ZZRDY Ready', RuleReadinessFilter::All, 1, 50))->items;

        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item->title] = $item->status;
        }
        assertSame(RuleReadinessStatus::Ready, $byTitle['ZZRDY Ready'] ?? null, 'status=queued but a completed file → Ready');
        assertSame(RuleReadinessStatus::Ready, $byTitle['ZZRDY ReadyReindex'] ?? null, 'a queued/failed reindex over an older completed snapshot stays Ready');
    }

    public function testFailedExcludesDocumentsThatStillHaveAUsableSnapshot(): void
    {
        $failed = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::Failed, 1, 50));

        assertSame(1, $failed->total);
        assertSame('ZZRDY Failed', $failed->items[0]->title);
    }

    public function testFilterCountsMatchTheTableTotals(): void
    {
        $summary = $this->reader->summary(self::MARK);
        foreach ([
            RuleReadinessFilter::Ready->value => $summary->ready,
            RuleReadinessFilter::Pending->value => $summary->pending(),
            RuleReadinessFilter::Failed->value => $summary->failed,
            RuleReadinessFilter::Disabled->value => $summary->disabled,
        ] as $filter => $expected) {
            $total = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::from($filter), 1, 50))->total;
            assertSame($expected, $total, "card count matches the $filter filter");
        }
    }

    public function testOnlyRuleDocumentsAppearAndSearchMatchesStoreAndId(): void
    {
        $all = $this->reader->list(new RuleReadinessQuery(self::MARK, RuleReadinessFilter::All, 1, 50));
        assertSame(7, $all->total);
        foreach ($all->items as $item) {
            assertTrue($item->title !== 'ZZRDY Knowledge' && $item->title !== 'ZZRDY Gone', 'non-rule and deleted excluded');
        }
    }

    public function testHiddenBaseScopeIncludesOnlyGlobalAndCommonDocuments(): void
    {
        $summary = $this->reader->summary(self::MARK, hiddenBaseOnly: true);

        // Only the three global docs (ReadyReindex, Indexing, Queued) — store docs are excluded.
        assertSame(1, $summary->ready);
        assertSame(1, $summary->indexing);
        assertSame(1, $summary->queued);
        assertSame(0, $summary->failed);
        assertSame(0, $summary->disabled);
        assertSame(3, $summary->total());
    }

    private function doc(string $title, string $sourceType, string $status, bool $enabled): int
    {
        $ref = 970000000 + (++$this->seq);
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $this->kbId,
            'original_filename' => 'r.md',
            'stored_path' => 'p/r' . $this->seq . '.md',
            'storage_token' => str_pad((string) $this->seq, 32, 'z', STR_PAD_LEFT),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_pad((string) $this->seq, 64, '0', STR_PAD_LEFT),
            'kind' => 'text',
            'source_type' => $sourceType,
            'source_ref' => (string) $ref,
            'title' => $title,
            'status' => $status,
            'is_enabled' => $enabled ? 1 : 0,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function file(int $documentId, string $indexStatus, ?string $openaiFileId): void
    {
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $documentId,
            'role' => 'source',
            'index_status' => $indexStatus,
            'openai_file_id' => $openaiFileId,
            'pending_removal' => 0,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand(
            'DELETE [[f]] FROM {{%document_index_files}} [[f]]'
            . ' JOIN {{%documents}} [[d]] ON [[d]].[[id]] = [[f]].[[document_id]]'
            . ' WHERE [[d]].[[title]] LIKE :mark',
            [':mark' => self::MARK . '%'],
        )->execute();
        $this->connection->createCommand(
            'DELETE FROM {{%documents}} WHERE [[title]] LIKE :mark',
            [':mark' => self::MARK . '%'],
        )->execute();
        // Removing the KB cascades any remaining documents; then drop the sentinel store.
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::KB_SLUG]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
    }
}
