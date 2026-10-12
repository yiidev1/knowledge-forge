<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Order58\Items\ItemCanonicalizer;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function gmdate;
use function hash;
use function json_encode;
use function random_bytes;
use function str_repeat;

/**
 * The original-vs-demo item comparison, rendered.
 *
 * ## What is really being guarded
 *
 * The defect, over HTTP. An original order whose line items have never been fetched used to make the
 * page report every line the trainee entered as "Extra in demo" — telling somebody who had recreated
 * the order perfectly that they had invented all of it. The page must say that the items are not
 * synchronized instead, and it must say it without accusing anybody.
 *
 * **No provider call.** The page reads two local tables; `ORDER58_ITEM_SYNC_ENABLED` is off and nothing
 * here presses the sync button.
 */
final class OrderTestingItemComparisonCest
{
    private const ADMIN = '__kf_item_compare_admin__';
    private const PASSWORD = 'ItemComparePassw0rd!secure';

    private const STORE_ID = 987655970;
    private const STORE_NAME = 'Item Comparison Fixture Store';
    private const HOST = 'kf-item-compare.order58.com';

    private const ORDER = 16800401;
    private const DEMO_ORDER = 16630821;
    private const PHONE = '19178532637';

    /** The client's own sample line, prefix and all. */
    private const WINGS = '[F7]Fried Chicken Wings (4)';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the defect

    /**
     * The whole point of this phase.
     *
     * Items never fetched, demo order full of lines: the page must not call them extra.
     */
    public function anUnsynchronizedOriginalDoesNotCallEveryDemoLineExtra(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings(), $this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('have not been synchronized yet');
        $I->see('Not comparable', '.ot-items');
        $I->dontSee('Extra in demo', '.ot-items');
        // The trainee's work is still fully on the page.
        $I->see(self::WINGS);
        $I->see('Wonton Soup');
    }

    /** Queued is the same promise: not comparable yet, and nobody is blamed. */
    public function aQueuedOriginalIsReportedAsPendingNotAsADifference(WebTester $I): void
    {
        $this->seedOrder('QUEUED');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('queued for synchronization');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    /** A failure with nothing ever stored says so, and says it is not the demo's fault. */
    public function aFailedSyncWithNoSnapshotSaysItIsNotADifference(WebTester $I): void
    {
        $this->seedOrder('FAILED');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('not a difference in the demo order');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    // ------------------------------------------------------------------ a real comparison

    public function aSynchronizedOriginalIsComparedLineByLine(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Match', '.ot-items');
        $I->dontSee('Not comparable', '.ot-items');
        $I->dontSee('have not been synchronized');
    }

    /** The provider's exact name is what the page shows — the prefix is kitchen routing, not noise. */
    public function theProvidersExactNameIncludingItsPrefixIsDisplayed(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see(self::WINGS);
    }

    /** A wrong quantity is one difference on one line, not a missing line plus an extra one. */
    public function aWrongQuantityIsOneDifferenceOnTheRightLine(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);

        $wrong = $this->wings();
        $wrong['qty'] = '3';
        $this->seedDemoOrder([$wrong]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Different', '.ot-items');
        $I->dontSee('Missing in demo', '.ot-items');
        $I->dontSee('Extra in demo', '.ot-items');
    }

    public function anOmittedLineIsMissingAndAnAddedLineIsExtra(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Missing in demo', '.ot-items');
        $I->see('Extra in demo', '.ot-items');
    }

    /** A verified empty original really does make a demo line extra. */
    public function anAuthoritativelyEmptyOriginalDoesReportExtraLines(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Extra in demo', '.ot-items');
        $I->dontSee('have not been synchronized');
    }

    /** The summary card names the state, so it is known before the table is read. */
    public function theSummaryCardNamesTheItemSyncState(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Original items', '.ot-summary');
        $I->see('Synced', '.ot-summary');
        $I->see('2026-10-09 10:00:00', '.ot-summary');
    }

    /** And it says "No items" rather than "0 items", which would read as a quiet failure. */
    public function anEmptyOriginalIsNamedAsSuchInTheSummary(WebTester $I): void
    {
        $this->seedOrder('EMPTY', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('No items', '.ot-summary');
        $I->dontSee('0 items', '.ot-summary');
    }

    public function anUnsynchronizedOriginalIsNamedInTheSummaryToo(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Not synced', '.ot-summary');
    }

    // ------------------------------------------------------------------ modifiers, by kind

    /**
     * Additions and combinations get their own rows.
     *
     * An operator fixes them differently — an addition is something asked for on top of the product, a
     * combination is a choice the product requires — so a single merged row would report a difference
     * without saying which kind.
     */
    public function additionsAndCombinationsAreShownAsSeparateRows(WebTester $I): void
    {
        $original = $this->wings();
        $original['data']['additionals'] = [
            ['additional_name' => 'Sauce', 'option_name' => 'Extra Hot', 'qty' => '2', 'price' => '0.50000'],
        ];
        $original['data']['combinations'] = [
            'options' => [['attribute_name' => 'Size', 'option_name' => 'Large']],
        ];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $original, 0);
        $this->seedDemoOrder([$original]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Additions', '.ot-items');
        $I->see('Combinations', '.ot-items');
        $I->see('Sauce: Extra Hot x2', '.ot-items');
        $I->see('Size: Large', '.ot-items');
    }

    /** A wrong addition is reported on the Additions row, and the combination row stays a match. */
    public function aWrongAdditionIsReportedOnItsOwnRow(WebTester $I): void
    {
        $original = $this->wings();
        $original['data']['additionals'] = [['additional_name' => 'Sauce', 'option_name' => 'Hot']];
        $original['data']['combinations'] = ['options' => [['attribute_name' => 'Size', 'option_name' => 'Large']]];

        $demo = $original;
        $demo['data']['additionals'] = [['additional_name' => 'Sauce', 'option_name' => 'Mild']];

        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $original, 0);
        $this->seedDemoOrder([$demo]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Different', '.ot-items');
        $I->see('Sauce: Hot', '.ot-items');
        $I->see('Sauce: Mild', '.ot-items');
        // The required choice was right, so its own row must not be dragged down with it.
        $I->see('Size: Large', '.ot-items');
    }

    /** Quantity, unit price and the product name each get a labelled row of their own. */
    public function productQuantityAndPriceEachHaveTheirOwnRow(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Product', '.ot-items');
        $I->see('Quantity', '.ot-items');
        $I->see('Unit price', '.ot-items');
        $I->see('11.35000', '.ot-items');
        // The un-prefixed name is what the Product row compares, so both forms appear on the page.
        $I->see('Fried Chicken Wings (4)', '.ot-items');
    }

    // ------------------------------------------------------------------ a kept snapshot

    public function aKeptSnapshotIsComparedAndLabelledStale(WebTester $I): void
    {
        $this->seedOrder('FAILED', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('most recent refresh failed');
        $I->see('2026-10-08 09:00:00');
        // Still compared: the kept snapshot is the only item data there is.
        $I->see('Match', '.ot-items');
    }

    public function aRefreshInFlightStillShowsTheLastGoodAnswer(WebTester $I): void
    {
        $this->seedOrder('PROCESSING', syncedAt: '2026-10-08 09:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('while a refresh is running');
        $I->see('Match', '.ot-items');
    }

    /** A line the provider removed is listed apart and never counted against the trainee. */
    public function aRemovedOriginalLineIsListedApart(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');

        $removed = $this->wings();
        $removed['deleted'] = '1';
        $this->seedItem(1, $removed, 0);
        $this->seedItem(2, $this->soup(), 1);
        $this->seedDemoOrder([$this->soup()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Removed from the original order');
        $I->seeElement('.ot-removed-items');
        $I->dontSee('Missing in demo', '.ot-items');
    }

    // ------------------------------------------------------------------ the rest of the page

    /** The non-item half of the comparison is untouched by any of this. */
    public function theOrderAndCustomerSectionsStillCompare(WebTester $I): void
    {
        $this->seedOrder('PENDING');
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('Customer');
        $I->see('Delivery address');
        $I->see('Totals');
        $I->see('Order items');
    }

    /** And still no score, which is a separate, separately specified piece of work. */
    public function thePageStillReportsNoAccuracyNumber(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedItem(1, $this->wings(), 0);
        $this->seedDemoOrder([$this->wings()]);

        $this->signIn($I);
        $I->amOnPage($this->url());

        $I->see('These are differences, not a score');
        $I->dontSee('%');
    }

    public function aGuestSeesNoneOfIt(WebTester $I): void
    {
        $this->seedOrder('SYNCED', syncedAt: '2026-10-09 10:00:00');
        $this->seedDemoOrder([$this->wings()]);

        $I->amOnPage($this->url());

        $I->seeCurrentUrlEquals('/login');
        $I->dontSee(self::WINGS);
    }

    // ------------------------------------------------------------------ harness

    private function url(): string
    {
        return '/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER
            . '/demo/' . self::DEMO_ORDER;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    /** @return array<string, mixed> */
    private function wings(): array
    {
        return [
            'id' => 45308981,
            'qty' => '1',
            'name' => self::WINGS,
            'price' => '11.35000',
            'desc' => null,
            'deleted' => '0',
            'order_id' => self::ORDER,
            'product_id' => 506941,
            'data' => [
                'product' => ['id' => 506941, 'sn' => 'F7', 'name' => 'Fried Chicken Wings (4)'],
                'additionals' => null,
                'combinations' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function soup(): array
    {
        return [
            'id' => 45308991,
            'qty' => '1',
            'name' => 'Wonton Soup',
            'price' => '2.00000',
            'deleted' => '0',
            'order_id' => self::ORDER,
            'product_id' => 538451,
            'data' => [
                'product' => ['id' => 538451, 'sn' => 'C12', 'name' => 'Wonton Soup'],
                'additionals' => null,
                'combinations' => null,
            ],
        ];
    }

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE_ID,
            'name' => self::STORE_NAME,
            'active' => 1,
            'host' => self::HOST,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-item-compare-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(string $itemsStatus, ?string $syncedAt = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        // Deliberately WITHOUT an `items` key: this is what the orders mirror actually holds, and the
        // reason the items come from their own table.
        $payload = (string) json_encode([
            'id' => (string) self::ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'data' => (string) json_encode(['reservation' => ['phone' => self::PHONE]]),
        ]);

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'reservation_phone' => self::PHONE,
            'type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '13.35000',
            'payload_json' => $payload,
            'content_hash' => hash('sha256', $payload),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
            'items_synced_at' => $syncedAt,
        ])->execute();
    }

    /** @param array<string, mixed> $raw */
    private function seedItem(int $sourceItemId, array $raw, int $position): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $canonical = (new ItemCanonicalizer())->canonicalize($raw);

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'source_item_id' => $sourceItemId,
            'name' => (string) ($raw['name'] ?? ''),
            'qty' => (string) ($raw['qty'] ?? '0'),
            'price' => $raw['price'] ?? null,
            'product_id' => $raw['product_id'] ?? null,
            'deleted' => (int) ($raw['deleted'] ?? 0),
            'position' => $position,
            'comparison_json' => (string) json_encode($canonical->toArray()),
            'content_hash' => hash('sha256', (string) json_encode($raw)),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @param list<array<string, mixed>> $items */
    private function seedDemoOrder(array $items): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode([
            'id' => self::DEMO_ORDER,
            'status' => 'completed',
            'type' => 'delivery',
            'total_amount' => '13.35000',
            'items' => $items,
            'data' => ['reservation' => ['phone' => self::PHONE, 'first_name' => 'testa']],
        ]);

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::ORDER,
            'demo_order_id' => self::DEMO_ORDER,
            'customer_phone' => self::PHONE,
            'customer_first_name' => 'testa',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '13.35000',
            'address' => '162 N Main St',
            'city' => 'Freeport',
            'source_filename' => self::ORDER . '-' . self::DEMO_ORDER . '.json',
            'content_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
    }
}
