<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreKnowledgeStatus;
use App\Order58\Infrastructure\DbStoreDirectoryReader;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Shared\Web\Support\AlphabetIndex;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function str_repeat;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertSame;

/**
 * The admin store directory against real MySQL: search isolates a set, the alphabet counts bucket correctly
 * (including "#"), and the friendly knowledge-status filter agrees with the computed badge. A unique name
 * marker keeps assertions deterministic against the shared dev database.
 */
final class StoreDirectoryReaderTest extends Unit
{
    private const MARK = 'ZZQATESTDIR'; // a unique substring in every seeded name, for search isolation
    private const ALPHA = 900000201;    // "AZZQATESTDIR" — made Ready (has an enabled ready document)
    private const BETA = 900000202;     // "BZZQATESTDIR" — Processing (KB still preparing, no documents)
    private const NUMERIC = 900000203;  // "9ZZQATESTDIR" — Processing; first char 9 buckets "#"

    private ConnectionInterface $connection;
    private DbStoreDirectoryReader $reader;
    private DbKnowledgeBaseSourceRepository $knowledgeBases;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->reader = new DbStoreDirectoryReader($this->connection);
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
        $ids = [self::ALPHA, self::BETA, self::NUMERIC];
        foreach ($this->kbIds($ids) as $kbId) {
            IntegrationDb::cleanup($this->connection, '{{%documents}}', ['knowledge_base_id' => $kbId]);
        }
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => $ids]);
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => $ids]);
    }

    /**
     * @param list<int> $sourceIds
     * @return list<int>
     */
    private function kbIds(array $sourceIds): array
    {
        $rows = $this->connection->createQuery()->select('id')->from('{{%knowledge_bases}}')
            ->where(['source_store_id' => $sourceIds])->column();

        return array_map(static fn(mixed $id): int => (int) $id, $rows);
    }

    private function seed(): void
    {
        $alphaKb = $this->seedOne(self::ALPHA, 'A' . self::MARK, 'Carmel');
        $this->seedOne(self::BETA, 'B' . self::MARK, 'Antioch');
        $this->seedOne(self::NUMERIC, '9' . self::MARK, 'Boston');

        // ALPHA gets an enabled, ready document → Ready (regardless of vector-store state). The others have
        // no documents and a still-preparing knowledge base → Processing.
        $this->seedReadyDocument($alphaKb);
    }

    private function seedOne(int $sourceId, string $name, string $city): int
    {
        $ts = DbDateTime::format($this->now);
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'company' => 'ACME',
            'active' => 1,
            'snapshot_json' => (string) json_encode([
                'id' => $sourceId,
                'name' => $name,
                'active' => true,
                'fields' => ['City' => $city, 'State' => 'XX'],
            ]),
            'sync_hash' => 'h' . $sourceId,
            'synced_at' => $ts,
            'last_seen_sync_run_id' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        return $this->knowledgeBases->createForSource($name, 'slug-' . $sourceId, 'order58', $sourceId, $name, true, $this->now);
    }

    private function seedReadyDocument(int $knowledgeBaseId): void
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
            'checksum_sha256' => str_repeat('a', 64),
            'kind' => 'text',
            'status' => 'ready',
            'is_enabled' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    /**
     * @param list<\App\Order58\Domain\StoreDirectoryItem> $items
     * @return list<int>
     */
    private function sourceIds(array $items): array
    {
        return array_map(static fn($item): int => $item->sourceId, $items);
    }

    public function testSearchIsolatesTheMarkedStores(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK));

        $ids = $this->sourceIds($result->items);
        assertContains(self::ALPHA, $ids);
        assertContains(self::BETA, $ids);
        assertContains(self::NUMERIC, $ids);
        assertSame(3, $result->countFor(AlphabetIndex::ALL));
    }

    public function testAlphabetCountsBucketNonAlphaUnderHash(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK));

        assertSame(1, $result->countFor('A'), 'Alpha');
        assertSame(1, $result->countFor('B'), 'Beta');
        assertSame(1, $result->countFor(AlphabetIndex::OTHER), '"9 ..." buckets under #');
    }

    public function testReadyFilterAndBadgeReflectAnEnabledReadyDocument(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK, filter: StoreDirectoryFilter::Ready));

        assertSame([self::ALPHA], $this->sourceIds($result->items));
        assertSame(StoreKnowledgeStatus::Ready, $result->items[0]->knowledgeStatus);
    }

    public function testProcessingFilterReturnsPreparingStoresWithoutReadyDocuments(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK, filter: StoreDirectoryFilter::Processing));

        $ids = $this->sourceIds($result->items);
        assertContains(self::BETA, $ids);
        assertContains(self::NUMERIC, $ids);
        assertNotContains(self::ALPHA, $ids, 'a Ready store must not appear under Processing');
        assertSame(StoreKnowledgeStatus::Processing, $result->items[0]->knowledgeStatus);
    }

    public function testLetterFilterNarrowsToOneBucket(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK, letter: 'A'));

        assertSame([self::ALPHA], $this->sourceIds($result->items));
    }

    public function testHashLetterFilterReturnsNumericNames(): void
    {
        $result = $this->reader->search(new StoreDirectoryQuery(search: self::MARK, letter: AlphabetIndex::OTHER));

        assertSame([self::NUMERIC], $this->sourceIds($result->items));
    }
}
