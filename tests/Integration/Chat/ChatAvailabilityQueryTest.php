<?php

declare(strict_types=1);

namespace App\Tests\Integration\Chat;

use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use App\Document\Infrastructure\DbDocumentRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function str_repeat;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

/**
 * The real usable-snapshot queries against MySQL. "Usable" reads the durable vector-store file
 * (`document_index_files.index_status='completed'` with an openai_file_id), not the mutable `documents.status`,
 * so a resync in progress or a failed refresh keeps chat available. Every query is scoped by
 * knowledge_base_id; two stores are verified not to influence each other.
 */
final class ChatAvailabilityQueryTest extends Unit
{
    private const SLUG_A = '__kf_avail_a__';
    private const SLUG_B = '__kf_avail_b__';

    private ConnectionInterface $connection;
    private DbDocumentRepository $documents;
    private int $kbA;
    private int $kbB;
    private int $seq = 0;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->now = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $this->kbA = $this->insertKnowledgeBase(self::SLUG_A);
        $this->kbB = $this->insertKnowledgeBase(self::SLUG_B);
        $this->documents = new DbDocumentRepository($this->connection, new SystemClock());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testProfileOnlyIsNotAQualifyingDocument(): void
    {
        $profile = $this->insertDoc($this->kbA, DocumentSourceType::Order58StoreProfile, DocumentStatus::Ready, true);
        $this->insertIndexFile($profile, IndexStatus::Completed, pendingRemoval: false, withFile: true);

        assertTrue($this->documents->hasUsableOrder58StoreProfile($this->kbA));
        assertFalse($this->documents->hasUsableQualifyingDocument($this->kbA), 'the store profile must not qualify');
    }

    public function testEnabledReadyKnowledgeRecordQualifies(): void
    {
        $doc = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, true);
        $this->insertIndexFile($doc, IndexStatus::Completed, pendingRemoval: false, withFile: true);

        assertTrue($this->documents->hasUsableQualifyingDocument($this->kbA));
    }

    public function testUploadedAndManualDocumentsQualify(): void
    {
        foreach ([DocumentSourceType::UploadedPdf, DocumentSourceType::ManualText] as $type) {
            $kb = $this->insertKnowledgeBase('__kf_avail_' . $type->value . '__');
            $doc = $this->insertDoc($kb, $type, DocumentStatus::Ready, true);
            $this->insertIndexFile($doc, IndexStatus::Completed, pendingRemoval: false, withFile: true);
            assertTrue($this->documents->hasUsableQualifyingDocument($kb), $type->value . ' should qualify');
            IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => '__kf_avail_' . $type->value . '__']);
        }
    }

    public function testInProgressStatesDoNotQualify(): void
    {
        // queued / processing / indexing / failed — none has a completed index file, none qualifies.
        foreach ([DocumentStatus::Queued, DocumentStatus::Processing, DocumentStatus::Indexing, DocumentStatus::Failed] as $status) {
            $doc = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, $status, true);
            // A pending/failed index file (never completed) does not count.
            $this->insertIndexFile($doc, IndexStatus::InProgress, pendingRemoval: false, withFile: true);
        }
        assertFalse($this->documents->hasUsableQualifyingDocument($this->kbA));
    }

    public function testDisabledAndDeletedDocumentsDoNotQualify(): void
    {
        $disabled = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, false);
        $this->insertIndexFile($disabled, IndexStatus::Completed, pendingRemoval: false, withFile: true);
        $deleted = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Deleted, true);
        $this->insertIndexFile($deleted, IndexStatus::Completed, pendingRemoval: false, withFile: true);

        assertFalse($this->documents->hasUsableQualifyingDocument($this->kbA));
    }

    public function testRefreshInProgressKeepsQualifyingViaOldCompletedFile(): void
    {
        // The document is mid-resync: status=queued, its previous completed file is retained
        // (pending_removal=1) while the new file is still in progress. It must remain usable.
        $doc = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Queued, true);
        $this->insertIndexFile($doc, IndexStatus::Completed, pendingRemoval: true, withFile: true);   // old, retained
        $this->insertIndexFile($doc, IndexStatus::InProgress, pendingRemoval: false, withFile: true); // new, indexing

        assertTrue($this->documents->hasUsableQualifyingDocument($this->kbA), 'a resync must not drop availability');
    }

    public function testFailedRefreshKeepsQualifyingViaOldCompletedFile(): void
    {
        $doc = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Failed, true);
        $this->insertIndexFile($doc, IndexStatus::Completed, pendingRemoval: true, withFile: true);  // old, retained
        $this->insertIndexFile($doc, IndexStatus::Failed, pendingRemoval: false, withFile: false);   // failed replacement

        assertTrue($this->documents->hasUsableQualifyingDocument($this->kbA), 'a failed refresh keeps the old snapshot');
    }

    public function testOneDisabledPlusOneEnabledReadyStillQualifies(): void
    {
        $disabled = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, false);
        $this->insertIndexFile($disabled, IndexStatus::Completed, pendingRemoval: false, withFile: true);
        $enabled = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, true);
        $this->insertIndexFile($enabled, IndexStatus::Completed, pendingRemoval: false, withFile: true);

        assertTrue($this->documents->hasUsableQualifyingDocument($this->kbA));
    }

    public function testCompletedFileWithoutOpenaiIdDoesNotQualify(): void
    {
        $doc = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, true);
        $this->insertIndexFile($doc, IndexStatus::Completed, pendingRemoval: false, withFile: false); // no openai_file_id

        assertFalse($this->documents->hasUsableQualifyingDocument($this->kbA));
    }

    public function testStoreIsolation(): void
    {
        // Store A: a usable qualifying record. Store B: only a store profile.
        $docA = $this->insertDoc($this->kbA, DocumentSourceType::Order58Knowledge, DocumentStatus::Ready, true);
        $this->insertIndexFile($docA, IndexStatus::Completed, pendingRemoval: false, withFile: true);
        $profileB = $this->insertDoc($this->kbB, DocumentSourceType::Order58StoreProfile, DocumentStatus::Ready, true);
        $this->insertIndexFile($profileB, IndexStatus::Completed, pendingRemoval: false, withFile: true);

        assertTrue($this->documents->hasUsableQualifyingDocument($this->kbA));
        assertFalse($this->documents->hasUsableOrder58StoreProfile($this->kbA), 'A has no profile');
        assertFalse($this->documents->hasUsableQualifyingDocument($this->kbB), 'B has only a profile');
        assertTrue($this->documents->hasUsableOrder58StoreProfile($this->kbB));
    }

    private function insertDoc(int $kbId, DocumentSourceType $sourceType, DocumentStatus $status, bool $enabled): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $kbId,
            'original_filename' => 'doc.md',
            'stored_path' => 'p/doc.md',
            'storage_token' => str_repeat('a', 32),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_pad((string) ++$this->seq, 64, '0', STR_PAD_LEFT),
            'kind' => 'text',
            'source_type' => $sourceType->value,
            'status' => $status->value,
            'is_enabled' => $enabled ? 1 : 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function insertIndexFile(int $documentId, IndexStatus $status, bool $pendingRemoval, bool $withFile): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $documentId,
            'role' => 'source',
            'index_status' => $status->value,
            'openai_file_id' => $withFile ? ('file_' . $documentId . '_' . ($pendingRemoval ? 'old' : 'new')) : null,
            'pending_removal' => $pendingRemoval ? 1 : 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function insertKnowledgeBase(string $slug): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Avail ' . $slug,
            'slug' => $slug,
            'vector_store_status' => 'ready',
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        foreach ([self::SLUG_A, self::SLUG_B] as $slug) {
            IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => $slug]);
        }
    }
}
