<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Infrastructure\DbAgentStoreDirectory;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function bin2hex;
use function in_array;
use function json_encode;
use function random_bytes;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * The agent store-availability predicate against real MySQL. A store is offered to agents only when it is
 * source-active, agent-enabled, vector-store ready, and has at least one enabled, ready document. The
 * lookup takes no agent identity, so `account_id` can play no part. Sentinel ids keep the shared dev
 * database undisturbed.
 */
final class AgentStoreDirectoryTest extends Unit
{
    private const ELIGIBLE = 900000301;
    private const INACTIVE = 900000302;
    private const DISABLED = 900000303;
    private const NOT_READY = 900000304;
    private const NO_DOCS = 900000305;

    private const ALL = [self::ELIGIBLE, self::INACTIVE, self::DISABLED, self::NOT_READY, self::NO_DOCS];

    private ConnectionInterface $connection;
    private DbAgentStoreDirectory $directory;
    private DbKnowledgeBaseSourceRepository $knowledgeBases;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->directory = new DbAgentStoreDirectory($this->connection);
        $this->knowledgeBases = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
        $this->seed();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach ($this->kbIds() as $kbId) {
            IntegrationDb::cleanup($this->connection, '{{%documents}}', ['knowledge_base_id' => $kbId]);
        }
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::ALL]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::ALL]);
    }

    /**
     * @return list<int>
     */
    private function kbIds(): array
    {
        $rows = $this->connection->createQuery()->select('id')->from('{{%knowledge_bases}}')
            ->where(['source_store_id' => self::ALL])->column();

        return array_map(static fn(mixed $id): int => (int) $id, $rows);
    }

    private function seed(): void
    {
        // sourceActive, agentEnabled, vectorReady, withDoc.
        $this->seedStore(self::ELIGIBLE, true);
        $this->seedKb(self::ELIGIBLE, 'zzqa-agent-eligible', sourceActive: true, agentEnabled: true, ready: true, withDoc: true);

        $this->seedStore(self::INACTIVE, false);
        $this->seedKb(self::INACTIVE, 'zzqa-agent-inactive', sourceActive: false, agentEnabled: true, ready: true, withDoc: true);

        $this->seedStore(self::DISABLED, true);
        $this->seedKb(self::DISABLED, 'zzqa-agent-disabled', sourceActive: true, agentEnabled: false, ready: true, withDoc: true);

        $this->seedStore(self::NOT_READY, true);
        $this->seedKb(self::NOT_READY, 'zzqa-agent-notready', sourceActive: true, agentEnabled: true, ready: false, withDoc: true);

        $this->seedStore(self::NO_DOCS, true);
        $this->seedKb(self::NO_DOCS, 'zzqa-agent-nodocs', sourceActive: true, agentEnabled: true, ready: true, withDoc: false);
    }

    private function seedStore(int $sourceId, bool $active): void
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'Agent Sentinel ' . $sourceId,
            'active' => $active ? 1 : 0,
            'snapshot_json' => (string) json_encode(['id' => $sourceId, 'name' => 'Agent Sentinel', 'active' => $active, 'fields' => ['City' => 'Testville']]),
            'sync_hash' => 'h' . $sourceId,
            'synced_at' => $ts,
            'last_seen_sync_run_id' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function seedKb(int $sourceId, string $slug, bool $sourceActive, bool $agentEnabled, bool $ready, bool $withDoc): void
    {
        $id = $this->knowledgeBases->createForSource('Agent Sentinel ' . $sourceId, $slug, 'order58', $sourceId, 'Agent Sentinel', $sourceActive, $this->now);

        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->update('{{%knowledge_bases}}', [
            'agent_enabled' => $agentEnabled ? 1 : 0,
            'vector_store_status' => $ready ? 'ready' : 'pending',
            // A Ready vector store must carry a (unique) id — the canonical policy null-checks it.
            'openai_vector_store_id' => $ready ? 'vs_agent_' . $sourceId : null,
            'updated_at' => $ts,
        ], ['id' => $id])->execute();

        if ($withDoc) {
            $this->seedReadyDocument($id);
        }
    }

    private function seedReadyDocument(int $knowledgeBaseId): void
    {
        // The canonical usable set for an Order58 base: a qualifying (knowledge) document AND the store-profile,
        // each with a completed, attached index-file snapshot — matching ChatAvailabilityPolicy /
        // KnowledgeBaseChatEligibilitySql (which key on document_index_files, not documents.status).
        $this->seedUsableDocument($knowledgeBaseId, 'order58_knowledge');
        $this->seedUsableDocument($knowledgeBaseId, 'order58_store_profile');
    }

    private function seedUsableDocument(int $knowledgeBaseId, string $sourceType): void
    {
        $ts = DbDateTime::format($this->now);
        $token = bin2hex(random_bytes(16));
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $knowledgeBaseId,
            'original_filename' => 'doc.md',
            'stored_path' => 'kb/' . $knowledgeBaseId . '/' . $token . '.md',
            'storage_token' => $token,
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => bin2hex(random_bytes(32)),
            'kind' => 'text',
            'source_type' => $sourceType,
            'status' => 'ready',
            'is_enabled' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        $docId = (int) $this->connection->getLastInsertID();
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $docId,
            'role' => 'source',
            'openai_file_id' => 'file_' . $token,
            'index_status' => 'completed',
            'pending_removal' => 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    /**
     * @return list<string>
     */
    private function availableSlugs(): array
    {
        return array_map(static fn($store): string => $store->slug, $this->directory->findAvailable());
    }

    public function testEligibleStoreIsAvailable(): void
    {
        assertContains('zzqa-agent-eligible', $this->availableSlugs());
        assertNotNull($this->directory->findAvailableBySlug('zzqa-agent-eligible'));
    }

    public function testInactiveDisabledNotReadyAndNoDocStoresAreExcluded(): void
    {
        $slugs = $this->availableSlugs();

        assertNotContains('zzqa-agent-inactive', $slugs, 'a source-inactive store must be hidden');
        assertNotContains('zzqa-agent-disabled', $slugs, 'an agent-disabled store must be hidden');
        assertNotContains('zzqa-agent-notready', $slugs, 'a not-ready knowledge base must be hidden');
        assertNotContains('zzqa-agent-nodocs', $slugs, 'a store with no enabled ready document must be hidden');

        assertNull($this->directory->findAvailableBySlug('zzqa-agent-inactive'));
        assertNull($this->directory->findAvailableBySlug('zzqa-agent-disabled'));
        assertNull($this->directory->findAvailableBySlug('zzqa-agent-notready'));
        assertNull($this->directory->findAvailableBySlug('zzqa-agent-nodocs'));
    }

    public function testAvailabilityTakesNoAgentIdentitySoAccountIdCannotFilter(): void
    {
        // findAvailable()/countAvailable() accept no agent or account_id argument: every active agent sees
        // the same set, so account_id can play no part in visibility.
        $slugs = $this->availableSlugs();
        assertContains('zzqa-agent-eligible', $slugs);

        // The count is consistent with the list (both use the identical predicate).
        assertSame(in_array('zzqa-agent-eligible', $slugs, true), $this->directory->countAvailable() >= 1);
    }
}
