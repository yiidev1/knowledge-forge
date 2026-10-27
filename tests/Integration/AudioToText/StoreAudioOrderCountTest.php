<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\OrderListingMode;
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

    /** The card and the page as recordings produce them: `ORDER58_CASE_ORDER_PRIMARY=false`. */
    private DbStoreAudioCounts $cards;
    private DbStoreOrderGroupRepository $page;

    /** The same pair as imported orders produce them: `ORDER58_CASE_ORDER_PRIMARY=true`, the default. */
    private DbStoreAudioCounts $cardsByOrder;
    private DbStoreOrderGroupRepository $pageByOrder;

    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        // The renditions repository is only consulted for the page's audio slots; `countFor()`
        // never reaches it, but the constructor requires one.
        $renditions = new DbTtsRenditionRepository($this->connection, new SystemClock());

        /*
         * Two pairs, because the card has to agree with the page under BOTH settings.
         *
         * Every case above the agreement test is about how recordings become rows, so it uses the
         * recording-primary pair — that is the rule those cases describe. The agreement test itself
         * asks both pairs the same question about the same store.
         */
        $this->cards = new DbStoreAudioCounts($this->connection, caseOrderPrimary: false);
        $this->page = new DbStoreOrderGroupRepository(
            $this->connection,
            $renditions,
            OrderListingMode::RecordingPrimary,
        );

        $this->cardsByOrder = new DbStoreAudioCounts($this->connection, caseOrderPrimary: true);
        $this->pageByOrder = new DbStoreOrderGroupRepository(
            $this->connection,
            $renditions,
            OrderListingMode::CaseOrderPrimary,
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
     *
     * ## Both settings, same data, one assertion each
     *
     * `ORDER58_CASE_ORDER_PRIMARY` decides which population a listing is a list of, and the card is
     * the headline for that listing — so the setting reaches both copies of the rule, and both copies
     * can now drift in two ways instead of one. The store below is deliberately seeded so the two
     * settings give *different* answers: four recording groups, two imported orders, overlapping in
     * one. A change that made either pair agree with the other pair would be the bug.
     */
    public function testTheCardAgreesWithThePageItLinksTo(): void
    {
        $this->seed('16800901', 'MIXED');
        $this->seed('16800901', 'CALLER');
        $this->seed('16800901', 'CALLEE');
        $this->seed('16800902', 'MIXED');
        $this->seed('111', 'MIXED');
        $this->seed(null, 'MIXED');

        // One of the recorded orders is mirrored, and one mirrored order was never recorded.
        $this->seedMirroredOrder(16800901);
        $this->seedMirroredOrder(16800903);

        $this->assertSame(
            $this->page->countFor(self::STORE),
            $this->cardCount(),
            'recording-primary: the card and the page must count the same things',
        );

        $this->assertSame(
            $this->pageByOrder->countFor(self::STORE),
            $this->cardCountByOrder(),
            'case-order-primary: the card and the page must count the same things',
        );

        /*
         * And the audio management surface, which answers for itself rather than from the setting.
         *
         * It asks both readers for recordings explicitly — see
         * `AudioToText\Web\Job\Store\Action::listingMode()` and
         * `Order58\Web\StoreAudio\Action::cardsCountImportedOrders()` — because its rows ARE
         * recordings: an upload that named no order can never be given one by an import, and that
         * page is the only place it can be replaced or transcribed.
         *
         * Asserted through the configured-for-orders pair, which is what makes this meaningful: both
         * objects are the ones a server with the setting ON would have, and they still agree on
         * recordings when a caller asks for them.
         */
        $this->assertSame(
            $this->pageByOrder->countFor(self::STORE, null, OrderListingMode::RecordingPrimary),
            $this->cardsByOrder->countsFor([self::STORE], false)[self::STORE]->orders,
            'the audio page and its card must agree even against the other setting',
        );

        // And the two settings really are being told apart, rather than both happening to be right
        // about one number.
        $this->assertSame(4, $this->cardCount(), 'three channels of one order, plus three more rows');
        $this->assertSame(2, $this->cardCountByOrder(), 'the two imported orders');
    }

    // ------------------------------------------------------------------ the two cards differ on purpose

    /**
     * Store #301, reproduced: one recording against an order no mirror row matches.
     *
     * **Both cards show 1.** The store really does hold one order's worth of audio, and two pickers
     * disagreeing about that reads as a bug whichever number is right. What differs is the second
     * figure: nothing here can be practised, because there is no source order to recreate.
     */
    public function testAnUnmirroredOrderCountsOnBothCardsButIsNotPractisable(): void
    {
        $this->seed('23113659', 'MIXED');

        self::assertSame(1, $this->cardCount(), 'both pickers show the store\'s real audio count');
        self::assertSame(0, $this->practisableCount(), 'and none of it can be practised');
    }

    /**
     * Store #1731: four orders with audio, three of them practisable.
     *
     * Both cards read 4. The Practice Orders card adds "3 available to practise", which is what its
     * own list will show — so the page below can hold fewer rows than the number above it without
     * either figure being wrong or unexplained.
     */
    public function testAMixOfMirroredAndUnmirroredOrdersSharesOneHeadline(): void
    {
        $this->seedMirroredOrder(16754561);
        $this->seedMirroredOrder(16758551);
        $this->seedMirroredOrder(16800401);

        foreach (['16754561', '16758551', '16800401'] as $orderId) {
            $this->seed($orderId, 'MIXED');
            $this->seed($orderId, 'CALLER');
            $this->seed($orderId, 'CALLEE');
        }

        // The one with no mirrored order behind it, recorded on two channels.
        $this->seed('111', 'MIXED');
        $this->seed('111', 'CALLER');

        self::assertSame(4, $this->cardCount(), 'four grouped orders have audio');
        self::assertSame(3, $this->practisableCount(), 'three of them can be practised');
        // And it is still orders being counted, not the eleven recordings underneath them.
        self::assertSame(11, $this->cards->countsFor([self::STORE])[self::STORE]->total);
    }

    /** An order-less upload can never be practised either: there is no order to recreate. */
    public function testAnUploadWithNoOrderIdIsNeverPractisable(): void
    {
        $this->seed(null, 'MIXED');

        self::assertSame(1, $this->cardCount());
        self::assertSame(0, $this->practisableCount());
    }

    /** The narrowing is store-scoped too: another store's mirrored order does not qualify ours. */
    public function testAMirroredOrderInAnotherStoreDoesNotMakeOursPractisable(): void
    {
        $this->seedMirroredOrder(16800901, self::OTHER_STORE);
        $this->seed('16800901', 'MIXED');

        self::assertSame(1, $this->cardCount());
        self::assertSame(0, $this->practisableCount());
    }

    /**
     * Every order practisable: the two figures agree, and the card says nothing extra.
     *
     * The template only prints "N available to practise" when the numbers differ, so this is the
     * case where both pickers are identical down to the last pixel.
     */
    public function testWhenEveryOrderIsPractisableTheTwoFiguresAgree(): void
    {
        $this->seedMirroredOrder(16800401);
        $this->seed('16800401', 'MIXED');
        $this->seed('16800401', 'CALLER');

        self::assertSame(1, $this->cardCount());
        self::assertSame(1, $this->practisableCount());
    }

    /** The smaller figure can never exceed the headline, whatever the data. */
    private function practisableCount(): int
    {
        $counts = $this->cards->countsFor([self::STORE]);

        if (!isset($counts[self::STORE])) {
            return 0;
        }

        self::assertLessThanOrEqual($counts[self::STORE]->orders, $counts[self::STORE]->practisable);

        return $counts[self::STORE]->practisable;
    }

    private function seedMirroredOrder(int $sourceOrderId, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $sourceOrderId,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** The headline both pickers show: every grouped order of this store that holds audio. */
    private function cardCount(): int
    {
        $counts = $this->cards->countsFor([self::STORE]);

        return isset($counts[self::STORE]) ? $counts[self::STORE]->orders : 0;
    }

    /** The same headline when the listing is a list of imported orders instead. */
    private function cardCountByOrder(): int
    {
        $counts = $this->cardsByOrder->countsFor([self::STORE]);

        return isset($counts[self::STORE]) ? $counts[self::STORE]->orders : 0;
    }

    private function seed(?string $orderId, string $type, int $store = self::STORE): void
    {
        $now = gmdate('Y-m-d H:i:s');
        /*
         * Hex, because the job routes constrain `{publicId}` to 32 hex characters — and `ef` rather
         * than the `cd` two other fixtures already use.
         *
         * `public_id` is UNIQUE across the whole table, and those fixtures pad the same counter the
         * same way, so a `cd`-prefixed seeder here produces byte-identical ids to theirs. They live
         * in different suites but share one database, so a run that died before its cleanup left
         * rows that made the next suite's insert fail — a collision that looks like a regression in
         * whichever test happens to run second.
         */
        $publicId = 'ef' . str_pad(dechex(++$this->seq), 30, '0', STR_PAD_LEFT);

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
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => [self::STORE, self::OTHER_STORE]])
            ->execute();
    }
}
