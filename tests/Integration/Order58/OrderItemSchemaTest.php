<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function gmdate;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;

/**
 * The `order58_order_items` schema, against real MySQL.
 *
 * ## What is really being guarded
 *
 * The migration makes three decisions that look like mistakes to anyone reading the table cold, and all
 * three are load-bearing:
 *
 * 1. **`account_id` holds `0`** — the provider's own item field — while `store_account_id` holds the
 *    real store. Somebody "fixing" that would silently break every store-scoped query.
 * 2. **`desc` is a MySQL reserved word**, kept because the decision was to preserve API names.
 * 3. **Five-decimal money** must survive a round trip; a float anywhere turns `11.35000` into something
 *    that no longer compares equal to the demo side.
 *
 * ## It skips until the migration is applied
 *
 * The migration is written but deliberately **not executed** — running it is a separate approval, and
 * it must not fire as a side effect of running the test suite. So these skip while the table is
 * absent, exactly as {@see IntegrationDb::connectOrSkip()} skips when there is no database at all.
 */
final class OrderItemSchemaTest extends Unit
{
    /** Far outside the mirrored range, so nothing here can collide with real data. */
    private const STORE = 987655920;
    private const ORDER = 987655921;

    private ConnectionInterface $connection;

    /** False until the guard in _before() has proved the table exists; _after() reads it. */
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();

        // FAIL CLOSED, before anything is written. These tests insert and delete rows, so the first
        // question has to be which database is actually on the other end — and configuration cannot
        // answer that reliably, because an env override, a .env edit and a server variable all claim
        // to decide it. DatabaseTarget asks the server and refuses anything it cannot prove local.
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        // A plain scalar query: `DATABASE()` has to be evaluated by MySQL, and wrapping it in an
        // Expression inside a where-array compares against the function's NAME, which silently matches
        // nothing and let these tests run against a table that does not exist.
        $exists = (int) $this->connection->createCommand(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order58_order_items'",
        )->queryScalar();

        if ($exists === 0) {
            $this->markTestSkipped(
                'order58_order_items does not exist — the migration has not been applied. '
                . 'This is the expected state until migration execution is approved.',
            );
        }

        $this->ready = true;
        $this->cleanup();
        $this->seedParentOrder();
    }

    protected function _after(): void
    {
        // `markTestSkipped()` throws out of _before(), but _after() still runs — so without this flag
        // the teardown would query a table the guard has just proved absent.
        if ($this->ready) {
            $this->cleanup();
        }
    }

    /**
     * Every one of the 19 provider fields survives a round trip unchanged.
     *
     * Built from the client's verbatim sample, including the `[F7]` prefix and the JSON-encoded `data`
     * **string**, because "preserve the API response" is the whole requirement this table exists to meet.
     */
    public function testEveryApiFieldRoundTripsUnchanged(): void
    {
        $data = '{"product":{"id":506941,"name":"Fried Chicken Wings (4)","tag":"","sn":"F7",'
            . '"cost_price":"10.35","price":"10.35","local_n":"0"},'
            . '"additionals":[{"additional_id":2711,"option_id":22821,"additional_name":"Add / Extra",'
            . '"option_name":"Add broccoli","extras":"1","qty":1,"price":"1.00","unit_price":1,'
            . '"target":"Fried Rice"}],'
            . '"combinations":{"id":418841,"name":null,"options":[{"attribute_id":3931,"option_id":23461,'
            . '"attribute_name":"Combination With","option_name":"Shrimp FR"}],"print_option":true}}';

        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'source_item_id' => 45308981,
            'name' => '[F7]Fried Chicken Wings (4)',
            'qty' => '1',
            'desc' => null,
            'price' => '11.35000',
            'cost_price' => '11.35000',
            'type' => null,
            'account_id' => 0,
            'user_id' => 0,
            'order_id' => self::ORDER,
            'data' => $data,
            'customer_id' => 4464241,
            'product_id' => 506941,
            'tag' => null,
            'pack_idx' => 1,
            'flag' => 'ADDED',
            'print_idx' => 0,
            'deleted' => 0,
            'free_qty' => '0',
            'position' => 0,
            'comparison_json' => (string) json_encode(['productId' => 506941]),
            'content_hash' => str_repeat('a', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        /** @var array<string, mixed> $row */
        $row = (new Query($this->connection))
            ->from('{{%order58_order_items}}')
            ->where(['store_account_id' => self::STORE, 'source_item_id' => 45308981])
            ->one();

        // The name keeps its prefix. Stripping happens only inside comparison_json.
        $this->assertSame('[F7]Fried Chicken Wings (4)', $row['name']);

        // The two that trip people up: these are the ITEM's fields, not the store.
        $this->assertSame(0, (int) $row['account_id'], 'API account_id must stay 0, not become the store.');
        $this->assertSame(0, (int) $row['user_id']);
        $this->assertSame(self::STORE, (int) $row['store_account_id'], 'The store lives here.');

        // Five decimals, never through a float.
        $this->assertSame('11.35000', (string) $row['price']);
        $this->assertSame('11.35000', (string) $row['cost_price']);
        $this->assertSame('1.000', (string) $row['qty']);
        $this->assertSame('0.000', (string) $row['free_qty']);

        $this->assertNull($row['desc'], 'A reserved-word column still round-trips a NULL.');
        $this->assertNull($row['type']);
        $this->assertNull($row['tag']);
        $this->assertSame('ADDED', $row['flag']);
        $this->assertSame(1, (int) $row['pack_idx']);
        $this->assertSame(0, (int) $row['print_idx']);
        $this->assertSame(0, (int) $row['deleted']);
        $this->assertSame(4464241, (int) $row['customer_id']);
        $this->assertSame(506941, (int) $row['product_id']);
        $this->assertSame(45308981, (int) $row['source_item_id']);

        // The nested data stays a STRING, byte for byte, with its modifiers intact.
        $this->assertSame($data, $row['data']);
        $decoded = json_decode((string) $row['data'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('F7', $decoded['product']['sn']);
        $this->assertSame('Add broccoli', $decoded['additionals'][0]['option_name']);
        $this->assertSame('Shrimp FR', $decoded['combinations']['options'][0]['option_name']);

        // And the local primary key is independent of the provider's item id.
        $this->assertNotSame(45308981, (int) $row['id']);
    }

    /** The triple is the identity: the same item twice is one row, not two. */
    public function testTheIdentityTripleIsUnique(): void
    {
        $this->insertMinimalItem(45308981);

        $this->expectException(Throwable::class);
        $this->insertMinimalItem(45308981);
    }

    /** An item whose parent order does not exist must not be storable. */
    public function testAnOrphanItemIsRejected(): void
    {
        $this->expectException(Throwable::class);

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => self::STORE,
            'source_order_id' => self::ORDER + 999,   // no such parent
            'source_item_id' => 1,
            'name' => 'Orphan',
            'qty' => '1',
            'position' => 0,
            'comparison_json' => '{}',
            'content_hash' => str_repeat('b', 64),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /**
     * An Orders Sync must never reset item state.
     *
     * `DbOrder58OrderRepository::upsert()` enumerates its `$insert` array and `ON DUPLICATE KEY UPDATE`
     * writes only the listed columns — so `items_*` survive. Adding them to that array would wipe every
     * order's item state on every sync, which is why this is pinned rather than assumed.
     */
    public function testAnOrderUpsertLeavesItemSyncStateAlone(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->update(
            '{{%order58_orders}}',
            ['items_status' => 'SYNCED', 'items_count' => 3, 'items_synced_at' => $now],
            ['account_id' => self::STORE, 'source_order_id' => self::ORDER],
        )->execute();

        // The same shape the real upsert writes: every column named, items_* absent.
        $this->connection->createCommand()->upsert(
            '{{%order58_orders}}',
            [
                'account_id' => self::STORE,
                'source_order_id' => self::ORDER,
                'status' => 'completed',
                'payload_json' => '{"id":1}',
                'content_hash' => str_repeat('d', 64),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['status' => 'cancelled', 'updated_at' => $now],
        )->execute();

        /** @var array<string, mixed> $row */
        $row = (new Query($this->connection))
            ->select(['status', 'items_status', 'items_count', 'items_synced_at'])
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE, 'source_order_id' => self::ORDER])
            ->one();

        $this->assertSame('cancelled', $row['status'], 'The upsert did update the order.');
        $this->assertSame('SYNCED', $row['items_status'], '…and left item state untouched.');
        $this->assertSame(3, (int) $row['items_count']);
        $this->assertNotNull($row['items_synced_at']);
    }

    /** Historical orders must start PENDING so the migration starts no backfill. */
    public function testANewOrderRowDefaultsToPending(): void
    {
        /** @var array<string, mixed> $row */
        $row = (new Query($this->connection))
            ->select(['items_status', 'items_count', 'items_attempts', 'items_synced_at'])
            ->from('{{%order58_orders}}')
            ->where(['account_id' => self::STORE, 'source_order_id' => self::ORDER])
            ->one();

        $this->assertSame('PENDING', $row['items_status']);
        $this->assertSame(0, (int) $row['items_count']);
        $this->assertSame(0, (int) $row['items_attempts']);
        $this->assertNull($row['items_synced_at']);
    }

    private function insertMinimalItem(int $sourceItemId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_order_items}}', [
            'store_account_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'source_item_id' => $sourceItemId,
            'name' => 'Item',
            'qty' => '1',
            'position' => 0,
            'comparison_json' => '{}',
            'content_hash' => bin2hex(random_bytes(32)),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedParentOrder(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'status' => 'completed',
            'payload_json' => '{"id":987655921}',
            'content_hash' => str_repeat('c', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own fixture ids, never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE])->execute();
    }
}
