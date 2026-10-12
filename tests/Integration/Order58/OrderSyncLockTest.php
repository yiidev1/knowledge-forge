<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Environment;
use App\Order58\Infrastructure\DbOrderSyncLock;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function random_bytes;

/**
 * The real advisory lock, against real MySQL.
 *
 * ## Why this cannot be a unit test
 *
 * `GET_LOCK` is scoped to a **connection**, and that is the entire mechanism: a second acquisition
 * fails only when it comes from somewhere else. A single-connection test would pass while proving
 * nothing, because MySQL grants the same session the same named lock again.
 *
 * So this test opens a **second connection** through the same factory the application uses, and that
 * second connection stands in for the other caller — the recording worker's process, or another
 * administrator's request.
 */
final class OrderSyncLockTest extends Unit
{
    private ConnectionInterface $first;
    private ConnectionInterface $second;
    private string $name;

    protected function _before(): void
    {
        $this->first = IntegrationDb::connectOrSkip();
        $this->second = $this->secondConnection();
        // Unique per run, so a crashed earlier run cannot make this one fail or pass spuriously.
        $this->name = 'kf-test-o58-lock-' . bin2hex(random_bytes(8));
    }

    protected function _after(): void
    {
        // Both, unconditionally: releasing a lock you do not hold is a no-op in MySQL.
        (new DbOrderSyncLock($this->first))->release($this->name);
        (new DbOrderSyncLock($this->second))->release($this->name);
    }

    public function testASecondConnectionIsRefusedWhileTheFirstHoldsIt(): void
    {
        $mine = new DbOrderSyncLock($this->first);
        $theirs = new DbOrderSyncLock($this->second);

        $this->assertTrue($mine->acquire($this->name), 'The first caller must get the lock.');
        $this->assertFalse(
            $theirs->acquire($this->name),
            'A second caller must be refused immediately — this is what stops the recording worker and '
            . 'the Orders page syncing the same store and day at once.',
        );
    }

    public function testReleasingHandsItToTheNextCaller(): void
    {
        $mine = new DbOrderSyncLock($this->first);
        $theirs = new DbOrderSyncLock($this->second);

        $this->assertTrue($mine->acquire($this->name));
        $mine->release($this->name);

        $this->assertTrue(
            $theirs->acquire($this->name),
            'A released lock must be immediately available; otherwise one sync blocks the next.',
        );
    }

    /** Different stores and ranges are different names, so one sync never blocks an unrelated one. */
    public function testDifferentNamesDoNotContend(): void
    {
        $mine = new DbOrderSyncLock($this->first);
        $theirs = new DbOrderSyncLock($this->second);
        $other = $this->name . '-other';

        $this->assertTrue($mine->acquire($this->name));
        $this->assertTrue($theirs->acquire($other));

        $theirs->release($other);
    }

    /**
     * A second connection built the same way the application builds its own.
     *
     * `IntegrationDb::connectOrSkip()` memoizes one connection on purpose, so this reaches for the
     * factory directly rather than defeating that memoization.
     */
    private function secondConnection(): ConnectionInterface
    {
        $params = new DbParams(
            host: Environment::string('DB_HOST'),
            port: Environment::int('DB_PORT'),
            name: Environment::string('DB_NAME'),
            user: Environment::string('DB_USER'),
            password: Environment::string('DB_PASSWORD'),
            charset: Environment::string('DB_CHARSET'),
            socket: Environment::string('DB_SOCKET'),
        );

        return (new DbConnectionFactory($params, new SchemaCache(new ArrayCache())))->create();
    }
}
