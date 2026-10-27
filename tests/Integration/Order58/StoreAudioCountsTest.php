<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function gmdate;
use function random_bytes;
use function PHPUnit\Framework\assertSame;

/**
 * The store-audio card counts, against real MySQL.
 *
 * ## What is really being guarded
 *
 * Two things that would be invisible if they broke:
 *
 * 1. **`total` must keep meaning what it has always meant** — rows in `audio_conversations` for the
 *    store. The same number drives the "Uploaded audio" filter, so a change here silently changes which
 *    stores that filter shows.
 * 2. **`recording_type` is nullable**, so the three named types do NOT add up to the total. A future
 *    change that quietly counted null as MIXED would claim a channel nobody stated, and every card
 *    would still look plausible.
 */
final class StoreAudioCountsTest extends Unit
{
    /** Far outside the mirrored range, so nothing here can collide with real data. */
    private const STORE_A = 987655400;
    private const STORE_B = 987655401;
    private const STORE_EMPTY = 987655402;

    private const USERNAME = '__kf_audio_counts_admin__';

    private ConnectionInterface $connection;
    private DbStoreAudioCounts $counts;
    private int $adminId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash('AudioCountsPassw0rd!secure'));

        $this->counts = new DbStoreAudioCounts($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the breakdown

    /** Each named type is counted into its own bucket, and only for its own store. */
    public function testEachRecordingTypeIsCountedSeparately(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');
        $this->conversation(self::STORE_A, 'CALLEE');

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(4, $b->total);
        assertSame(2, $b->mixed);
        assertSame(1, $b->caller);
        assertSame(1, $b->callee);
        assertSame(0, $b->other());
    }

    /**
     * The one that matters most: a null recording type is reported as Other, never as Mixed.
     *
     * Most rows in this database are null - the column is newer than the feature - so folding them
     * into Mixed would be both wrong and completely believable on screen.
     */
    public function testNullRecordingTypeIsReportedAsOtherAndNeverAsMixed(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(4, $b->total);
        assertSame(1, $b->mixed, 'A null recording type must not be counted as Mixed.');
        assertSame(3, $b->other());
    }

    /** A SEPARATE upload records no recording type; its mode already says what it is. */
    public function testASeparateUploadCountsAsOneConversationUnderOther(): void
    {
        $this->conversation(self::STORE_A, null, mode: 'SEPARATE');

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(1, $b->total, 'A Customer + Agent pair is ONE conversation, not two.');
        assertSame(1, $b->other());
    }

    /** Other is always total minus the three, so the four values can never disagree. */
    public function testOtherIsAlwaysTheRemainder(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame($b->total - $b->mixed - $b->caller - $b->callee, $b->other());
        assertSame(3, $b->other());
    }

    // ------------------------------------------------------------------ isolation

    /** Counts never leak between stores. */
    public function testCountsAreIsolatedByStoreSourceId(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_B, 'CALLER');

        $result = $this->counts->countsFor([self::STORE_A, self::STORE_B]);

        assertSame(2, $result[self::STORE_A]->total);
        assertSame(2, $result[self::STORE_A]->mixed);
        assertSame(0, $result[self::STORE_A]->caller);

        assertSame(1, $result[self::STORE_B]->total);
        assertSame(1, $result[self::STORE_B]->caller);
        assertSame(0, $result[self::STORE_B]->mixed);
    }

    /**
     * A store with nothing is ABSENT from the result, not present as a zero row.
     *
     * The card defaults, and an absent key and a zero mean the same thing - so the query does not have
     * to carry rows that say nothing.
     */
    public function testAStoreWithNoAudioIsAbsentRatherThanZero(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');

        $result = $this->counts->countsFor([self::STORE_A, self::STORE_EMPTY]);

        self::assertArrayHasKey(self::STORE_A, $result);
        self::assertArrayNotHasKey(self::STORE_EMPTY, $result);
    }

    /** No ids, no query. */
    public function testAnEmptyIdListReturnsNothing(): void
    {
        assertSame([], $this->counts->countsFor([]));
    }

    // ------------------------------------------------------------------ performance

    /**
     * The N+1 guard.
     *
     * A page shows up to 36 stores. If the breakdown ever became a query per card this would be 36
     * round trips to print a few dozen numbers, and nothing on screen would look different - which is
     * exactly why it needs a test rather than a code review.
     *
     * ## Measured as "does the cost grow", not as an exact number
     *
     * It used to assert exactly one query, which was the same thing while there was one population to
     * count. There are two now — recordings, and the imported orders the card headlines when
     * `ORDER58_CASE_ORDER_PRIMARY` is on — so a correct implementation issues a small fixed number
     * rather than one. Pinning the number would make adding an honest second grouped query fail a
     * test about N+1, and the next person would widen the number rather than ask why.
     *
     * So one store and twelve are measured, and they must cost the same. That is the property: the
     * cost is set by the page, not by how many cards are on it. A query per card still fails
     * immediately — twelve cards would cost eleven more statements than one.
     */
    public function testTheBreakdownForManyStoresCostsWhatOneStoreCosts(): void
    {
        $ids = [];

        for ($i = 0; $i < 12; $i++) {
            $id = self::STORE_A + 100 + $i;
            $ids[] = $id;
            $this->conversation($id, $i % 2 === 0 ? 'MIXED' : 'CALLER');
        }

        // The probe is itself a statement, so two back-to-back readings differ by the probe's own
        // cost. Measure that first and subtract it, rather than hard-coding an offset that would
        // silently drift if the probe ever changed.
        $calibrationStart = $this->queryCount();
        $overhead = $this->queryCount() - $calibrationStart;

        $before = $this->queryCount();
        $one = $this->counts->countsFor([$ids[0]]);
        $forOne = $this->queryCount() - $before - $overhead;

        $before = $this->queryCount();
        $result = $this->counts->countsFor($ids);
        $forTwelve = $this->queryCount() - $before - $overhead;

        assertSame(1, count($one));
        assertSame(12, count($result));
        assertSame(
            $forOne,
            $forTwelve,
            'countsFor() must cost the same however many stores a page shows.',
        );
    }

    // ------------------------------------------------------------------ the filter source

    /**
     * `storesWithAudio()` still keys on "has a conversation at all", which is what "Uploaded audio"
     * has always meant. The breakdown must not have redefined it.
     */
    public function testStoresWithAudioIgnoresRecordingTypeEntirely(): void
    {
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_B, 'CALLEE');

        $withAudio = $this->counts->storesWithAudio();

        self::assertContains(self::STORE_A, $withAudio, 'A null recording type is still uploaded audio.');
        self::assertContains(self::STORE_B, $withAudio);
        self::assertNotContains(self::STORE_EMPTY, $withAudio);
    }

    // -------------------------------------------------- the Case Orders data source

    /**
     * **Only stores that actually have an order, and audio has no say in it.**
     *
     * The filter's whole promise. A store with two hundred orders and nothing ever recorded is the
     * normal case since the Case Order sync — it was invisible on this page before, because the only
     * narrowing available read `audio_conversations`.
     */
    public function testStoresWithCaseOrdersListsOnlyStoresHoldingOrders(): void
    {
        // Orders, no audio.
        $this->order(self::STORE_A, 5001);
        $this->order(self::STORE_A, 5002);
        // Audio, no orders.
        $this->conversation(self::STORE_B, 'MIXED');

        $stores = $this->counts->storesWithCaseOrders();

        self::assertContains(self::STORE_A, $stores, 'orders and no audio still qualifies');
        self::assertNotContains(self::STORE_B, $stores, 'audio alone must not qualify');
        self::assertNotContains(self::STORE_EMPTY, $stores);
    }

    /** Several orders for one store list that store once — the directory must not show duplicates. */
    public function testAStoreWithManyOrdersAppearsOnce(): void
    {
        foreach ([6001, 6002, 6003, 6004] as $orderId) {
            $this->order(self::STORE_A, $orderId);
        }

        $stores = $this->counts->storesWithCaseOrders();

        self::assertSame(
            [self::STORE_A],
            array_values(array_filter($stores, static fn(int $id): bool => $id === self::STORE_A)),
            'four orders, one store entry',
        );
    }

    /** The two data sources answer independently: neither narrows by the other's table. */
    public function testTheTwoDataSourcesAreIndependent(): void
    {
        $this->order(self::STORE_A, 7001);
        $this->conversation(self::STORE_B, 'MIXED');

        $counts = $this->counts;

        self::assertContains(self::STORE_A, $counts->storesWithCaseOrders());
        self::assertNotContains(self::STORE_A, $counts->storesWithAudio());

        self::assertContains(self::STORE_B, $counts->storesWithAudio());
        self::assertNotContains(self::STORE_B, $counts->storesWithCaseOrders());
    }

    /**
     * In Case Orders mode the headline is the order count, not the recording count.
     *
     * The specific defect this feature removes: the card read `order58_orders` under the words
     * "Audio Recordings". The number was right; it was being described as the other thing.
     */
    public function testTheHeadlineCountsOrdersInCaseOrdersMode(): void
    {
        $this->order(self::STORE_A, 8001);
        $this->order(self::STORE_A, 8002);
        $this->order(self::STORE_A, 8003);
        // Two recordings as well, so the two numbers are distinguishable.
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');

        $breakdown = $this->counts->countsFor([self::STORE_A], true)[self::STORE_A] ?? null;

        self::assertNotNull($breakdown);
        self::assertSame(3, $breakdown->orders, 'the headline is the order count');
        self::assertSame(2, $breakdown->total, 'and the recordings are still reported beside it');
    }

    /** And in Uploaded Audio mode it is the recording count, from the same reader. */
    public function testTheHeadlineCountsRecordingsInAudioMode(): void
    {
        $this->order(self::STORE_A, 9001);
        $this->order(self::STORE_A, 9002);
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');

        $breakdown = $this->counts->countsFor([self::STORE_A], false)[self::STORE_A] ?? null;

        self::assertNotNull($breakdown);
        self::assertSame(2, $breakdown->total, 'two recordings');
        self::assertNotSame(2, 3, 'and the order count is not what the card shows here');
    }

    // ------------------------------------------------------------------ plumbing

    /** One mirrored order for a store. Only the columns the counts actually read. */
    private function order(int $store, int $sourceOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'is_test' => 0,
            'frontend' => 0,
            'paid' => 0,
            'payload_json' => '{}',
            'content_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => 'PENDING',
            'items_count' => 0,
            'items_attempts' => 0,
        ])->execute();
    }


    private function conversation(int $store, ?string $recordingType, string $mode = 'COMMON'): void
    {
        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => $store,
            'mode' => $mode,
            'recording_type' => $recordingType,
            'order_id' => null,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /** MySQL's own count of statements this session has issued. */
    private function queryCount(): int
    {
        /** @var array{Variable_name: string, Value: string}|false $row */
        $row = $this->connection
            ->createCommand("SHOW SESSION STATUS LIKE 'Questions'")
            ->queryOne();

        return (int) ($row['Value'] ?? 0);
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();

        $ids = [self::STORE_A, self::STORE_B, self::STORE_EMPTY];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = self::STORE_A + 100 + $i;
        }

        $connection->createCommand()->delete('{{%audio_conversations}}', ['store_source_id' => $ids])->execute();
        // The orders these cases seed, by the same fixture ids and nothing else.
        $connection->createCommand()->delete('{{%order58_orders}}', ['account_id' => $ids])->execute();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
