<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Chat\Application\ChatAvailabilityPolicy;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Order58\Domain\StoreAgentAvailabilityFilter;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Order58\Infrastructure\DbStoreDirectoryReader;
use App\Document\Infrastructure\DbDocumentRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function bin2hex;
use function random_bytes;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The admin store directory's SQL chat-eligibility + knowledge counts against real MySQL, and — critically —
 * a per-store cross-check that the SQL reason equals the PHP {@see ChatAvailabilityPolicy} verdict, proving the
 * one canonical definition is shared (no divergent duplicated readiness). Sentinel ids keep the shared DB clean.
 */
final class StoreDirectoryEligibilityTest extends Unit
{
    private const ELIGIBLE = 901000401;
    private const INACTIVE = 901000402;
    private const PENDING = 901000403;
    private const NO_QUALIFYING = 901000404;
    private const NO_PROFILE = 901000405;
    private const AGENT_OFF = 901000406;
    private const RULES_ONLY = 901000407;
    private const MANUAL_ONLY = 901000408;
    private const PDF_ONLY = 901000409;
    private const RULES_PLUS_MANUAL = 901000410;
    private const RULES_PLUS_KNOWLEDGE = 901000411;
    private const ALL = [
        901000401, 901000402, 901000403, 901000404, 901000405, 901000406,
        901000407, 901000408, 901000409, 901000410, 901000411,
    ];

    private ConnectionInterface $connection;
    private DbStoreDirectoryReader $reader;
    private DbKnowledgeBaseSourceRepository $sources;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->reader = new DbStoreDirectoryReader($this->connection);
        $this->sources = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();

        // slug, sourceActive, agentEnabled, ready(+vector id), qualifyingDoc(order58_knowledge), profileDoc.
        $this->seed(self::ELIGIBLE, 'zzelig-eligible', true, true, true, true, true);
        $this->seed(self::INACTIVE, 'zzelig-inactive', false, true, true, true, true);
        $this->seed(self::PENDING, 'zzelig-pending', true, true, false, true, true);
        $this->seed(self::NO_QUALIFYING, 'zzelig-noqual', true, true, true, false, true);
        $this->seed(self::NO_PROFILE, 'zzelig-noprofile', true, true, true, true, false);
        $this->seed(self::AGENT_OFF, 'zzelig-agentoff', true, false, true, true, true);

        // Rule-only: store profile + every rule projection ready, but NO genuine content → NOT chattable.
        $rulesOnly = $this->seedStore(self::RULES_ONLY, 'zzelig-rulesonly', true, true, true);
        $this->seedUsableDocument($rulesOnly, 'order58_store_profile');
        $this->seedUsableDocument($rulesOnly, 'order58_rule_store');
        $this->seedUsableDocument($rulesOnly, 'order58_rule_global');
        $this->seedUsableDocument($rulesOnly, 'order58_rule_common');

        // Manual text is genuine content → chattable even with zero order58_knowledge documents.
        $manual = $this->seedStore(self::MANUAL_ONLY, 'zzelig-manual', true, true, true);
        $this->seedUsableDocument($manual, 'order58_store_profile');
        $this->seedUsableDocument($manual, 'manual_text');

        // Uploaded PDF is genuine content → chattable.
        $pdf = $this->seedStore(self::PDF_ONLY, 'zzelig-pdf', true, true, true);
        $this->seedUsableDocument($pdf, 'order58_store_profile');
        $this->seedUsableDocument($pdf, 'uploaded_pdf');

        // Rules + manual text → chattable because of the manual text (the rules alone would not qualify).
        $rulesManual = $this->seedStore(self::RULES_PLUS_MANUAL, 'zzelig-rulesmanual', true, true, true);
        $this->seedUsableDocument($rulesManual, 'order58_store_profile');
        $this->seedUsableDocument($rulesManual, 'order58_rule_store');
        $this->seedUsableDocument($rulesManual, 'manual_text');

        // Rules + Order58 knowledge → chattable because of the Order58 knowledge document.
        $rulesKnowledge = $this->seedStore(self::RULES_PLUS_KNOWLEDGE, 'zzelig-rulesknowledge', true, true, true);
        $this->seedUsableDocument($rulesKnowledge, 'order58_store_profile');
        $this->seedUsableDocument($rulesKnowledge, 'order58_rule_global');
        $this->seedUsableDocument($rulesKnowledge, 'order58_knowledge');
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testReasonMatrixMatchesTheCanonicalRule(): void
    {
        $items = $this->itemsBySlug();

        assertTrue($items['zzelig-eligible']->chatEligible);
        assertSame(null, $items['zzelig-eligible']->chatReason);
        assertSame('source_inactive', $items['zzelig-inactive']->chatReason);
        assertSame('not_provisioned', $items['zzelig-pending']->chatReason);
        assertSame('order58_not_ready', $items['zzelig-noqual']->chatReason);
        assertSame('order58_not_ready', $items['zzelig-noprofile']->chatReason);
        // agent_enabled does not affect chat-eligibility (it is a separate admin axis).
        assertTrue($items['zzelig-agentoff']->chatEligible);

        // Rule projections (store/global/common) are NOT qualifying content: a store whose only ready content is
        // rules (plus the profile) must stay blocked. Genuine content (manual text, uploaded PDF) enables chat.
        assertFalse($items['zzelig-rulesonly']->chatEligible);
        assertSame('order58_not_ready', $items['zzelig-rulesonly']->chatReason);
        assertTrue($items['zzelig-manual']->chatEligible);
        assertSame(null, $items['zzelig-manual']->chatReason);
        assertTrue($items['zzelig-pdf']->chatEligible);
        assertTrue($items['zzelig-rulesmanual']->chatEligible);
        assertTrue($items['zzelig-rulesknowledge']->chatEligible);
    }

    public function testGenuineContentEnablesChatEvenWithZeroOrder58KnowledgeCount(): void
    {
        // The user's explicit expectation: the "Order58 Knowledge" count may read 0 · 0/0 while chat is enabled,
        // because manual/uploaded genuine content — not the informational knowledge-record count — is the gate.
        $manual = $this->itemsBySlug()['zzelig-manual'];
        assertTrue($manual->chatEligible);
        assertSame(0, $manual->knowledgeDocsTotal);
        assertSame(0, $manual->knowledgeDocsIndexed);
    }

    public function testSqlEligibilityAgreesWithThePhpPolicyForEveryStore(): void
    {
        $documents = new DbDocumentRepository($this->connection, new SystemClock());
        $policy = new ChatAvailabilityPolicy($documents, $this->sources);
        $kbs = new DbKnowledgeBaseRepository($this->connection, new SystemClock());

        foreach ($this->itemsBySlug() as $slug => $item) {
            $kb = $kbs->findBySlug($slug);
            assertNotNull($kb, $slug);
            $expected = $policy->getUnavailableReason($kb);
            // SQL 'available' ⇔ policy Available; otherwise the reason codes match exactly.
            assertSame(
                $expected->value === 'available' ? null : $expected->value,
                $item->chatReason,
                "SQL and policy disagree for $slug",
            );
            assertSame($expected->isAvailable(), $item->chatEligible, "eligibility flag disagrees for $slug");
        }
    }

    public function testKnowledgeCountsCountKnowledgeDocsAndIndexedSubset(): void
    {
        $eligible = $this->itemsBySlug()['zzelig-eligible'];
        // One order58_knowledge doc, indexed (completed snapshot); the store-profile is not a "knowledge" doc.
        assertSame(1, $eligible->knowledgeDocsTotal);
        assertSame(1, $eligible->knowledgeDocsIndexed);
    }

    public function testSourceAndAgentFiltersAreIndependentAndSql(): void
    {
        $inactive = $this->slugs(new StoreDirectoryQuery('zzelig', StoreDirectoryFilter::All, 'all', 1, 50, false, StoreSourceStatusFilter::Inactive));
        assertSame(['zzelig-inactive'], $inactive);

        $agentOff = $this->slugs(new StoreDirectoryQuery('zzelig', StoreDirectoryFilter::All, 'all', 1, 50, false, StoreSourceStatusFilter::All, StoreAgentAvailabilityFilter::Disabled));
        assertSame(['zzelig-agentoff'], $agentOff);
    }

    /**
     * @return array<string, StoreDirectoryItem>
     */
    private function itemsBySlug(): array
    {
        $result = $this->reader->search(new StoreDirectoryQuery('zzelig', StoreDirectoryFilter::All, 'all', 1, 50));
        $out = [];
        foreach ($result->items as $item) {
            $out[$item->slug] = $item;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function slugs(StoreDirectoryQuery $query): array
    {
        return array_map(static fn(StoreDirectoryItem $i): string => $i->slug, $this->reader->search($query)->items);
    }

    private function seed(int $sourceId, string $slug, bool $sourceActive, bool $agentEnabled, bool $ready, bool $qualifying, bool $profile): void
    {
        $id = $this->seedStore($sourceId, $slug, $sourceActive, $agentEnabled, $ready);

        if ($qualifying) {
            $this->seedUsableDocument($id, 'order58_knowledge');
        }
        if ($profile) {
            $this->seedUsableDocument($id, 'order58_store_profile');
        }
    }

    /**
     * Seeds the store + knowledge base skeleton (mirror row, KB, vector state) and returns the KB id, so a test
     * can then attach exactly the usable documents it needs via {@see seedUsableDocument()}.
     */
    private function seedStore(int $sourceId, string $slug, bool $sourceActive, bool $agentEnabled, bool $ready): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => 'zzelig Sentinel ' . $sourceId,
            'active' => $sourceActive ? 1 : 0,
            'snapshot_json' => '{"fields":{"City":"Testville"}}',
            'sync_hash' => 'h' . $sourceId,
            'synced_at' => $ts,
            'last_seen_sync_run_id' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        $id = $this->sources->createForSource('zzelig Sentinel ' . $sourceId, $slug, 'order58', $sourceId, 'Elig Sentinel', $sourceActive, $this->now);
        $this->connection->createCommand()->update('{{%knowledge_bases}}', [
            'agent_enabled' => $agentEnabled ? 1 : 0,
            'vector_store_status' => $ready ? 'ready' : 'pending',
            'openai_vector_store_id' => $ready ? 'vs_elig_' . $sourceId : null,
            'updated_at' => $ts,
        ], ['id' => $id])->execute();

        return $id;
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

    private function cleanup(): void
    {
        $rows = $this->connection->createQuery()->select('id')->from('{{%knowledge_bases}}')
            ->where(['source_store_id' => self::ALL])->column();
        foreach ($rows as $kbId) {
            $this->connection->createCommand(
                'DELETE [[f]] FROM {{%document_index_files}} [[f]]'
                . ' JOIN {{%documents}} [[d]] ON [[d]].[[id]] = [[f]].[[document_id]]'
                . ' WHERE [[d]].[[knowledge_base_id]] = :kb',
                [':kb' => (int) $kbId],
            )->execute();
            IntegrationDb::cleanup($this->connection, '{{%documents}}', ['knowledge_base_id' => (int) $kbId]);
        }
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::ALL]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::ALL]);
    }
}
