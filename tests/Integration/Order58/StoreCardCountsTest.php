<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Order58\Infrastructure\DbStoreTopicAssignmentCounts;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function str_repeat;

/**
 * The two numbers a store card shows, counted the way the card claims to count them.
 *
 * Both are aggregates over real rows, so both are asserted against a real MySQL: a `COUNT(DISTINCT)`
 * that is actually a `COUNT` only shows itself when a topic is used twice, and a join that multiplies
 * only shows itself when an order has more than one recording. Each of those shapes is seeded here on
 * purpose.
 *
 * Fixture store ids sit in the `9876559xx` range and cleanup is by id.
 */
final class StoreCardCountsTest extends Unit
{
    private const STORE = 987655890;
    private const OTHER_STORE = 987655891;

    private ConnectionInterface $connection;
    private DbStoreAudioCounts $audio;
    private DbStoreTopicAssignmentCounts $topics;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->audio = new DbStoreAudioCounts($this->connection);
        $this->topics = new DbStoreTopicAssignmentCounts($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /**
     * Audio Count is the store's recordings, and one call's three channels are three recordings.
     *
     * Each uploaded recording is one `audio_conversations` row carrying its own recording type, so the
     * count the card shows is the count of those rows — the same number the directory has always
     * shown, now labelled. Measured against the live store 1731 while this was written: 14
     * conversations, 14 jobs holding retained audio.
     */
    public function testAudioCountIsTheNumberOfRecordedConversations(): void
    {
        $this->seedConversation('MIXED');
        $this->seedConversation('CALLER');
        $this->seedConversation('CALLEE');
        $this->seedConversation(null);

        $counts = $this->audio->countsFor([self::STORE, self::OTHER_STORE]);

        self::assertSame(4, $counts[self::STORE]->total);
        self::assertArrayNotHasKey(self::OTHER_STORE, $counts, 'a store with none has no row at all');
    }

    /**
     * Each assigned order counts, however many of them share a topic.
     *
     * This is the metric's second definition. The first counted distinct topics, which is a fair
     * question and not the one the card is read for: a store with three orders all under "Test2"
     * showed a 1 while its own page showed three assigned rows.
     */
    public function testEveryAssignedOrderCountsEvenWhenTheyShareATopic(): void
    {
        $topic = $this->seedTopic('ZZ Card Topic A');

        $this->seedOrder(16800401);
        $this->seedOrder(16800402);
        $this->seedOrder(16800403);
        $this->assign(16800401, $topic);
        $this->assign(16800402, $topic);
        $this->assign(16800403, $topic);

        $counts = $this->topics->assignedOrdersFor([self::STORE, self::OTHER_STORE]);

        self::assertSame(3, $counts[self::STORE], 'three orders under one topic is three orders');
        self::assertArrayNotHasKey(self::OTHER_STORE, $counts, 'an unassigned store has no row');
    }

    public function testThreeOrdersUnderThreeTopicsIsAlsoThree(): void
    {
        foreach ([16800401, 16800402, 16800403] as $i => $order) {
            $this->seedOrder($order);
            $this->assign($order, $this->seedTopic('ZZ Card Topic ' . ['A', 'B', 'C'][$i]));
        }

        self::assertSame(3, $this->topics->assignedOrdersFor([self::STORE])[self::STORE]);
    }

    /** An order with no topic is not an assignment, and contributes nothing. */
    public function testOrdersWithNoTopicAreNotCounted(): void
    {
        $this->seedOrder(16800401);
        $this->seedOrder(16800402);
        $this->assign(16800401, $this->seedTopic('ZZ Card Topic A'));

        self::assertSame(1, $this->topics->assignedOrdersFor([self::STORE])[self::STORE]);
    }

    /**
     * An assignment whose order is no longer in the mirror describes an order this store does not have.
     *
     * The service only ever writes an assignment against an order it found, but the mirror is a copy
     * of somebody else's table and a resync can take a row away. The card must not count what its own
     * store page cannot show.
     */
    public function testAnAssignmentWhoseOrderIsGoneIsNotCounted(): void
    {
        $this->seedOrder(16800401);
        $this->assign(16800401, $this->seedTopic('ZZ Card Topic A'));
        // Assigned, but its order was never mirrored at all.
        $this->assign(16800999, $this->seedTopic('ZZ Card Topic B'));

        self::assertSame(1, $this->topics->assignedOrdersFor([self::STORE])[self::STORE]);
    }

    public function testAnOrderWithSeveralRecordingsDoesNotInflateTheTopicCount(): void
    {
        $topic = $this->seedTopic('ZZ Card Topic A');
        $this->seedOrder(16800401);
        $this->assign(16800401, $topic);

        // The same order, recorded three times over — the shape that would multiply a count built by
        // joining recordings to assignments.
        $this->seedConversation('MIXED', 16800401);
        $this->seedConversation('CALLER', 16800401);
        $this->seedConversation('CALLEE', 16800401);

        self::assertSame(1, $this->topics->assignedOrdersFor([self::STORE])[self::STORE]);
    }

    public function testAnotherStoresAssignmentsNeverLeakIn(): void
    {
        $topic = $this->seedTopic('ZZ Card Topic A');

        // The same order NUMBER in both stores, which is the collision the composite key exists for.
        $this->seedOrder(16800401, self::STORE);
        $this->seedOrder(16800401, self::OTHER_STORE);
        $this->assign(16800401, $topic, self::STORE);
        $this->assign(16800401, $topic, self::OTHER_STORE);

        $counts = $this->topics->assignedOrdersFor([self::STORE, self::OTHER_STORE]);

        self::assertSame(1, $counts[self::STORE]);
        self::assertSame(1, $counts[self::OTHER_STORE]);
    }

    /** A topic kept for history still describes what that order is practised under. */
    public function testAnArchivedTopicStillCountsWhileItIsStillAssigned(): void
    {
        $topic = $this->seedTopic('ZZ Card Topic A');
        $this->seedOrder(16800401);
        $this->assign(16800401, $topic);

        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['status' => 'ARCHIVED'], ['id' => $topic])
            ->execute();

        self::assertSame(1, $this->topics->assignedOrdersFor([self::STORE])[self::STORE]);
    }

    public function testNeitherReaderQueriesAtAllForAnEmptyPage(): void
    {
        self::assertSame([], $this->audio->countsFor([]));
        self::assertSame([], $this->topics->assignedOrdersFor([]));
    }

    // ---------------------------------------------------------------- fixtures

    private function seedConversation(?string $type, int $orderId = 16800401): void
    {
        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => str_repeat('0', 24) . str_repeat((string) $this->nextSuffix(), 8),
            'store_source_id' => self::STORE,
            'mode' => 'COMMON',
            'recording_type' => $type,
            'order_id' => (string) $orderId,
            'generate_ai_audio' => 0,
            // The uploader CHECK admits exactly one realm's id and insists on it, so a fixture row
            // cannot be anonymous. Any administrator id will do here: the count is about the store.
            'uploaded_by_type' => 'admin',
            'uploaded_by_admin_id' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    private int $suffix = 1;

    private function nextSuffix(): int
    {
        return $this->suffix++;
    }

    private function seedTopic(string $name): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_topics}}', [
            'name' => $name,
            'name_key' => $name,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function seedOrder(int $orderId, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $orderId,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function assign(int $orderId, int $topicId, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => $store,
            'source_order_id' => $orderId,
            'topic_id' => $topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        foreach ([self::STORE, self::OTHER_STORE] as $store) {
            $this->connection->createCommand()
                ->delete('{{%practice_order_topics}}', ['store_source_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['store_source_id' => $store])->execute();
            $this->connection->createCommand()
                ->delete('{{%order58_orders}}', ['account_id' => $store])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%practice_topics}}', ['name' => ['ZZ Card Topic A', 'ZZ Card Topic B', 'ZZ Card Topic C']])->execute();
    }
}
