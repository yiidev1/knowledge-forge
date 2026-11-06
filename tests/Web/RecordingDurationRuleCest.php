<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Environment;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Codeception\Scenario;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function hash;
use function json_decode;
use function str_repeat;
use function time;
use function trim;
use function urlencode;

/**
 * The recording-duration rule on the real pages: Manage Orders and Practice Orders list a store's orders
 * minus the one whose recording is longer than its amount allows, their picker cards count the same, and
 * the page's duration request answers a stored length without asking the provider.
 *
 * Three recent orders of $84.48 (limit 1013.76 s): one recording of 900 s, one of 1014 s, one not
 * measured yet. Runs only with RECORDING_DURATION_FILTER_ENABLED=true — the served application reads it.
 */
final class RecordingDurationRuleCest
{
    private const USERNAME = '__kf_duration_rule_admin__';
    private const PASSWORD = 'DurationRulePassw0rd!secure';
    private const STORE = 987655969;
    private const STORE_NAME = 'ZZ Duration Rule Web Store';
    private const WITHIN = 16920001;
    private const OVER = 16920002;
    private const UNMEASURED = 16920003;

    private ConnectionInterface $connection;

    /** @var array<int, int> order => recording id */
    private array $recordings = [];

    public function _before(WebTester $I, Scenario $scenario): void
    {
        if (!Environment::bool('RECORDING_DURATION_FILTER_ENABLED')) {
            $scenario->skip('RECORDING_DURATION_FILTER_ENABLED is off in this environment.');
        }

        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
        $this->seedOrder(self::WITHIN, 900.0);
        $this->seedOrder(self::OVER, 1014.0);
        $this->seedOrder(self::UNMEASURED, null);

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function manageOrdersHidesTheOrderWhoseRecordingIsTooLong(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE);
        $I->seeResponseCodeIs(200);

        $I->see('#' . self::WITHIN);
        $I->see('#' . self::UNMEASURED, '.ot-orders');
        $I->dontSee('#' . self::OVER);
        $I->see('of 2 orders');
    }

    public function practiceOrdersHidesItToo(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE);
        $I->seeResponseCodeIs(200);

        $I->see('#' . self::WITHIN);
        $I->see('#' . self::UNMEASURED);
        $I->dontSee('#' . self::OVER);
    }

    /** Both pickers count what their store pages list: two orders, not three. */
    public function thePickerCardsCountTheSameOrders(WebTester $I): void
    {
        foreach (['/order-testing', '/practice-orders'] as $picker) {
            $I->amOnPage($picker . '?q=' . urlencode(self::STORE_NAME));
            $I->see(self::STORE_NAME, '.store-card__name');
            Assert::assertSame(
                '2',
                trim((string) $I->grabTextFrom('.store-card .store-card__metric:first-child .store-card__metric-value')),
                $picker,
            );
        }
    }

    /** A stored length is answered from the database — no request leaves for the provider. */
    public function theDurationRequestAnswersAStoredLength(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE . '/order/' . self::WITHIN
            . '/recording/' . $this->recordings[self::WITHIN] . '?duration=1');

        $I->seeResponseCodeIs(200);
        Assert::assertSame(['seconds' => 900], json_decode($I->grabPageSource(), true));
    }

    /** Only the listing is narrowed: the hidden order's own recording address still answers. */
    public function aHiddenOrdersRecordingIsStillReachableDirectly(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE . '/order/' . self::OVER
            . '/recording/' . $this->recordings[self::OVER] . '?duration=1');

        $I->seeResponseCodeIs(200);
        Assert::assertSame(['seconds' => 1014], json_decode($I->grabPageSource(), true));
    }

    // ---------------------------------------------------------------- fixtures

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE,
            'name' => self::STORE_NAME,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-duration-rule-' . self::STORE,
            'source_system' => 'order58',
            'source_store_id' => self::STORE,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** A recent $84.48 order with one current call recording of this length (null: not measured yet). */
    private function seedOrder(int $order, ?float $duration): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE,
            'source_order_id' => $order,
            'total_amount' => '84.48000',
            // Placed yesterday. Its age does not matter: the pickers have no date window.
            'source_created_at' => time() - 86_400 + ($order - self::WITHIN),
            'payload_json' => '{}',
            'content_hash' => hash('sha256', (string) $order),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $session = (string) ($order - 16_000_000);
        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => self::STORE,
            'source_order_id' => $order,
            'call_session_id' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $sessionRow = (int) $this->connection->getLastInsertID();

        $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
            . '?time=2026-11-05&company=SWCC&name=' . $order;
        $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
            'session_id' => $sessionRow,
            'position' => 0,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'duration_seconds' => $duration,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
        $this->recordings[$order] = (int) $this->connection->getLastInsertID();
    }

    /** Scoped to this suite's own administrator and store; never a blanket delete. */
    private function cleanup(): void
    {
        // Sessions and recordings cascade from the orders.
        $this->connection->createCommand()->delete('{{%order58_orders}}', ['account_id' => self::STORE])->execute();
        $this->connection->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE])->execute();
        $this->connection->createCommand()->delete('{{%order58_stores}}', ['source_id' => self::STORE])->execute();

        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }
    }
}
