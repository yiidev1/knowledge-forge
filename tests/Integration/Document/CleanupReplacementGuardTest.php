<?php

declare(strict_types=1);

namespace App\Tests\Integration\Document;

use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Infrastructure\DbIndexedFileRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function str_pad;
use function str_repeat;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;

/**
 * The RemoteCleanupDrainer replacement guard. An old flagged file is removable ONLY when its document is
 * deleted/disabled, OR a completed, non-flagged replacement definitely exists. Critically, "no incomplete
 * replacement exists" is NOT treated as "a completed replacement exists": a resync whose new file has not
 * been created yet must keep its old file, or the knowledge base is briefly left with no usable copy.
 */
final class CleanupReplacementGuardTest extends Unit
{
    private const SLUG = '__kf_cleanup_guard__';

    private ConnectionInterface $connection;
    private DbIndexedFileRepository $indexedFiles;
    private int $kbId;
    private int $seq = 0;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->now = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $this->kbId = $this->insertKnowledgeBase();
        $this->indexedFiles = new DbIndexedFileRepository($this->connection, new SystemClock());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testOldCompletedFileWithNoReplacementIsRetained(): void
    {
        // The resync's new file has not been created yet — the old file must be kept, not dropped.
        $doc = $this->insertDoc('ready', enabled: true);
        $old = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);

        assertNotContains($old, $this->pendingRemovalIds(), 'no replacement row yet ⇒ retain the old file');
    }

    public function testOldCompletedFileWithFailedReplacementIsRetained(): void
    {
        $doc = $this->insertDoc('failed', enabled: true);
        $old = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);
        $this->insertFile($doc, IndexStatus::Failed, pendingRemoval: false);

        assertNotContains($old, $this->pendingRemovalIds(), 'a failed replacement is not a completed one');
    }

    public function testFailedReplacementRemovedLeavesOldRetained(): void
    {
        $doc = $this->insertDoc('failed', enabled: true);
        $old = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);
        $failed = $this->insertFile($doc, IndexStatus::Failed, pendingRemoval: false);

        // The failed replacement row is cleaned up, leaving only the old completed file — still retained.
        $this->connection->createCommand()->delete('{{%document_index_files}}', ['id' => $failed])->execute();

        assertNotContains($old, $this->pendingRemovalIds(), 'still no completed replacement ⇒ retain the old file');
    }

    public function testCompletedReplacementMakesOldEligible(): void
    {
        $doc = $this->insertDoc('ready', enabled: true);
        $old = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);
        $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: false); // completed replacement

        assertContains($old, $this->pendingRemovalIds(), 'a completed replacement ⇒ the old file is removable');
    }

    public function testDeletedDocumentMakesFileEligible(): void
    {
        $doc = $this->insertDoc('deleted', enabled: true);
        $file = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);

        assertContains($file, $this->pendingRemovalIds(), 'a deleted document ⇒ its file is removable');
    }

    public function testDisabledDocumentMakesFileEligible(): void
    {
        $doc = $this->insertDoc('ready', enabled: false);
        $file = $this->insertFile($doc, IndexStatus::Completed, pendingRemoval: true);

        assertContains($file, $this->pendingRemovalIds(), 'a disabled document ⇒ its file is removable');
    }

    public function testGuardIsPerDocument(): void
    {
        // Doc A: mid-resync, new file not completed → its old file is retained.
        $docA = $this->insertDoc('ready', enabled: true);
        $keptA = $this->insertFile($docA, IndexStatus::Completed, pendingRemoval: true);
        $this->insertFile($docA, IndexStatus::InProgress, pendingRemoval: false);
        // Doc B: deleted → its file is removable.
        $docB = $this->insertDoc('deleted', enabled: true);
        $releasedB = $this->insertFile($docB, IndexStatus::Completed, pendingRemoval: true);

        $ids = $this->pendingRemovalIds();
        assertNotContains($keptA, $ids);
        assertContains($releasedB, $ids);
    }

    /**
     * @return list<int>
     */
    private function pendingRemovalIds(): array
    {
        return array_map(static fn($f): int => $f->id, $this->indexedFiles->findPendingRemoval(100));
    }

    private function insertDoc(string $status, bool $enabled): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $this->kbId,
            'original_filename' => 'd.md',
            'stored_path' => 'p/d.md',
            'storage_token' => str_repeat('a', 32),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_pad((string) ++$this->seq, 64, '0', STR_PAD_LEFT),
            'kind' => 'text',
            'source_type' => 'order58_knowledge',
            'status' => $status,
            'is_enabled' => $enabled ? 1 : 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function insertFile(int $documentId, IndexStatus $status, bool $pendingRemoval): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $documentId,
            'role' => 'source',
            'index_status' => $status->value,
            'openai_file_id' => 'file_' . $documentId . '_' . ($pendingRemoval ? 'old' : 'new'),
            'pending_removal' => $pendingRemoval ? 1 : 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function insertKnowledgeBase(): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Cleanup Guard',
            'slug' => self::SLUG,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection ?? IntegrationDb::connectOrSkip(), '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
