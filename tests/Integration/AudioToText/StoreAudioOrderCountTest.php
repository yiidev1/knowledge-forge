<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Infrastructure\DbStoreOrderGroupRepository;
use App\AudioToText\Infrastructure\Tts\DbTtsRenditionRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function gmdate;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * The number on a store card is a count of ORDERS, not of recordings.
 *
 * A call recorded on three channels is three rows in `audio_conversations` and one row on the store
 * page. The card used to show the first number and therefore said "14" for a store whose page lists
 * four — the same data counted at the wrong level.
 *
 * ## Why this test spans two modules
 *
 * `ModuleIsolationTest` forbids Order58 from naming Audio-to-Text and the reverse, so the grouping
 * rule exists twice: once as `GroupKey::sqlExpression()`, which the page groups by, and once copied
 * into `DbStoreAudioCounts`, which the card counts by. Neither can import the other.
 *
 * **So the guard is that both are asked the same question here and must give the same answer.** A
 * change to either copy that drifts fails in this file rather than on a card nobody is checking.
 *
 * Fixture ids sit in the `9876559xx` range, well outside anything mirrored, and every row is removed
 * again.
 */
final class StoreAudioOrderCountTest extends Unit
{
    private const STORE = 987655970;
    private const OTHER_STORE = 987655971;

    private ConnectionInterface $connection;
    private DbStoreAudioCounts $cards;
    private DbStoreOrderGroupRepository $page;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->cards = new DbStoreAudioCounts($this->connection);
        // The renditions repository is only consulted for the page's audio slots; `countFor()`
        // never reaches it, but the constructor requires one.
        $this->page = new DbStoreOrderGroupRepository(
            $this->connection,
            new DbTtsRenditionRepository($this->connection, new SystemClock()),
        );
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** One order, one channel. */
    public function testAnOrderWithOneRecordingCountsOnce(): void
    {
        $this->seed('16800501', 'MIXED');

        $this->assertSame(1, $this->cardCount());
    }

    /** Each of the three channels alone is still one order. */
    public function testEachSingleChannelCountsOnce(): void
    {
        foreach (['MIXED', 'CALLER', 'CALLEE'] as $i => $type) {
            $this->seed('168006' . $i . '0', $type);
        }

        $this->assertSame(3, $this->cardCount());
    }

    /** The case the bug was actually about: three recordings of one call are one call. */
    public function testOneOrderWithAllThreeChannelsCountsOnce(): void
    {
        $this->seed('16800601', 'MIXED');
        $this->seed('16800601', 'CALLER');
        $this->seed('16800601', 'CALLEE');

        $this->assertSame(1, $this->cardCount());
    }

    /** Re-uploads and extra versions of the same channel do not multiply the order either. */
    public function testRepeatedRecordingsOfOneOrderStillCountOnce(): void
    {
        $this->seed('16800602', 'MIXED');
        $this->seed('16800602', 'MIXED');
        $this->seed('16800602', 'MIXED');

        $this->assertSame(1, $this->cardCount());
    }

    /** The example from the brief: five orders × three recordings is five, not fifteen. */
    public function testFiveOrdersWithThreeRecordingsEachCountFive(): void
    {
        for ($i = 0; $i < 5; $i++) {
            foreach (['MIXED', 'CALLER', 'CALLEE'] as $type) {
                $this->seed('168007' . $i . '0', $type);
            }
        }

        $this->assertSame(5, $this->cardCount());
        // And the recording total is still the recording total — it was not redefined, only unused.
        $this->assertSame(15, $this->cards->countsFor([self::STORE])[self::STORE]->total);
    }

    /** A store with nothing uploaded is absent from the map, which the card reads as zero. */
    public function testAStoreWithNoAudioCountsZero(): void
    {
        $this->assertSame(0, $this->cardCount());
    }

    /**
     * An upload that named no order is its own row on the page, so it is its own one on the card.
     *
     * This is the half a naive `COUNT(DISTINCT order_id)` would get wrong: it would fold every
     * order-less upload in the store into a single NULL, or drop them entirely.
     */
    public function testUploadsWithNoOrderIdEachCountOnce(): void
    {
        $this->seed(null, 'MIXED');
        $this->seed(null, 'MIXED');

        $this->assertSame(2, $this->cardCount());
    }

    /**
     * An order id that matches nothing in `order58_orders` still counts.
     *
     * The live store's `#111` row is exactly this: the page lists it, so the card counts it. Audio
     * the reader can open must never be missing from the number above it.
     */
    public function testAnUnmatchedOrderIdStillCounts(): void
    {
        $this->seed('111', 'MIXED');

        $this->assertSame(1, $this->cardCount());
    }

    /** Two stores holding the same order number are two separate ones. */
    public function testStoresAreCountedInIsolation(): void
    {
        $this->seed('16800801', 'MIXED');
        $this->seed('16800801', 'MIXED', self::OTHER_STORE);
        $this->seed('16800802', 'MIXED', self::OTHER_STORE);

        $counts = $this->cards->countsFor([self::STORE, self::OTHER_STORE]);

        $this->assertSame(1, $counts[self::STORE]->orders);
        $this->assertSame(2, $counts[self::OTHER_STORE]->orders);
    }

    /**
     * The drift guard, and the point of this file.
     *
     * The card and the page count with two separate copies of one rule, because the module isolation
     * tests forbid them from sharing code. Here they are asked about the same store and must agree —
     * so the number on the card is always the number of rows behind it.
     */
    public function testTheCardAgreesWithThePageItLinksTo(): void
    {
        $this->seed('16800901', 'MIXED');
        $this->seed('16800901', 'CALLER');
        $this->seed('16800901', 'CALLEE');
        $this->seed('16800902', 'MIXED');
        $this->seed('111', 'MIXED');
        $this->seed(null, 'MIXED');

        $this->assertSame(
            $this->page->countFor(self::STORE),
            $this->cardCount(),
            'The store card and the store page must count the same things.',
        );
    }

    private function cardCount(): int
    {
        $counts = $this->cards->countsFor([self::STORE]);

        return isset($counts[self::STORE]) ? $counts[self::STORE]->orders : 0;
    }

    private function seed(?string $orderId, string $type, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');
        // Hex, because the job routes constrain `{publicId}` to 32 hex characters.
        $publicId = 'cd' . str_pad(dechex(++$this->seq), 30, '0', STR_PAD_LEFT);

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $publicId,
            'store_source_id' => $store,
            'mode' => 'COMMON',
            'recording_type' => $type,
            'order_id' => $orderId,
            'generate_ai_audio' => 0,
            // `chk_audio_conversations_uploader` requires exactly one of the two uploader ids, paired
            // with its type. A fixture that skipped it would be refused by the database.
            'uploaded_by_type' => 'admin',
            'uploaded_by_admin_id' => 1,
            'created_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%audio_conversations}}', ['store_source_id' => [self::STORE, self::OTHER_STORE]])
            ->execute();
    }
}
