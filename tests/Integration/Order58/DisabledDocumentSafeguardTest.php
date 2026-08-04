<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Document\Application\Validation\SafeFilenameGenerator;
use App\Document\Domain\DocumentSourceType;
use App\Document\Infrastructure\DbGeneratedDocumentRepository;
use App\Order58\Application\GeneratedDocumentUpsert;
use App\Order58\Application\SyncDocumentService;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\Fake\Document\InMemoryDocumentStorage;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\Document\InMemoryProcessingEventRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_repeat;
use function PHPUnit\Framework\assertSame;

/**
 * The admin-disable safeguard: a resync must never silently re-enable a document an admin explicitly
 * disabled, and must keep an already-enabled document enabled while it re-indexes. Verified against the
 * real Order58 sync path (SyncDocumentService → DbGeneratedDocumentRepository::reindex).
 */
final class DisabledDocumentSafeguardTest extends Unit
{
    private const SLUG = '__kf_disabled_safeguard__';

    private ConnectionInterface $connection;
    private SyncDocumentService $service;
    private int $kbId;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->now = new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('UTC'));
        $this->kbId = $this->insertKnowledgeBase();
        $this->service = new SyncDocumentService(
            new DbGeneratedDocumentRepository($this->connection),
            new InMemoryDocumentStorage(),
            new InMemoryIndexedFileRepository(),
            new InMemoryProcessingEventRepository(),
            new SafeFilenameGenerator(),
        );
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testResyncDoesNotReEnableAnAdminDisabledDocument(): void
    {
        $id = $this->insertGeneratedDoc('rec-1', 'hash-1', enabled: false, status: 'ready');

        $result = $this->service->upsertGenerated(
            $this->kbId,
            DocumentSourceType::Order58Knowledge,
            'rec-1',
            'Updated title',
            'hash-2-changed',
            'new upstream content',
            $this->now,
        );

        assertSame(GeneratedDocumentUpsert::SkippedDisabled, $result);
        // The document stays disabled and is NOT re-queued for indexing.
        assertSame(0, $this->columnOf($id, 'is_enabled'));
        assertSame('ready', $this->statusOf($id));
    }

    public function testResyncKeepsAnEnabledDocumentEnabledWhileReindexing(): void
    {
        $id = $this->insertGeneratedDoc('rec-2', 'hash-1', enabled: true, status: 'ready');

        $result = $this->service->upsertGenerated(
            $this->kbId,
            DocumentSourceType::Order58Knowledge,
            'rec-2',
            'Updated title',
            'hash-2-changed',
            'new upstream content',
            $this->now,
        );

        assertSame(GeneratedDocumentUpsert::Updated, $result);
        // Still enabled (so its retained completed snapshot keeps chat available), now re-queued.
        assertSame(1, $this->columnOf($id, 'is_enabled'));
        assertSame('queued', $this->statusOf($id));
    }

    private function insertGeneratedDoc(string $sourceRef, string $syncHash, bool $enabled, string $status): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $this->kbId,
            'original_filename' => $sourceRef . '.md',
            'stored_path' => 'kb/' . $sourceRef . '.md',
            'storage_token' => str_repeat('a', 32),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_repeat('0', 63) . ($enabled ? '1' : '0'),
            'kind' => 'text',
            'source_type' => DocumentSourceType::Order58Knowledge->value,
            'source_ref' => $sourceRef,
            'source_sync_hash' => $syncHash,
            'status' => $status,
            'is_enabled' => $enabled ? 1 : 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function columnOf(int $id, string $column): int
    {
        return (int) $this->connection->createQuery()->select($column)->from('{{%documents}}')->where(['id' => $id])->scalar();
    }

    private function statusOf(int $id): string
    {
        return (string) $this->connection->createQuery()->select('status')->from('{{%documents}}')->where(['id' => $id])->scalar();
    }

    private function insertKnowledgeBase(): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Disabled Safeguard',
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
