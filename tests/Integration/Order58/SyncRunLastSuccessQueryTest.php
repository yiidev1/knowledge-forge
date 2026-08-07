<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Domain\Order58SyncType;
use App\Order58\Infrastructure\DbSyncRunRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertSame;

/**
 * `lastSuccessfulAtByType()` against real MySQL: it returns the latest completion among *successful* runs and a
 * failed run — even a later one — is never counted, so a failure can never overwrite the last-successful time.
 * Uses a sentinel admin id + far-future timestamps so the assertion is independent of any real rows.
 */
final class SyncRunLastSuccessQueryTest extends Unit
{
    private const ADMIN = 970700701;
    private const SUCCESS_AT = '2035-01-01 03:00:00';
    private const LATER_FAILURE_AT = '2036-01-01 03:00:00';

    private ConnectionInterface $connection;
    private DbSyncRunRepository $repo;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repo = new DbSyncRunRepository($this->connection, new SystemClock());
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testLastSuccessfulIgnoresLaterFailures(): void
    {
        $this->insertRun('completed', self::SUCCESS_AT);
        $this->insertRun('failed', self::LATER_FAILURE_AT);

        $map = $this->repo->lastSuccessfulAtByType();

        $expected = new DateTimeImmutable(self::SUCCESS_AT, new DateTimeZone('UTC'));
        assertSame(
            $expected->getTimestamp(),
            $map[Order58SyncType::Rules->value]->getTimestamp(),
            'the later failure must not become the last-successful time',
        );
    }

    public function testCompletedWithWarningsCountsAsSuccessful(): void
    {
        $this->insertRun('completed_with_warnings', self::SUCCESS_AT);

        $map = $this->repo->lastSuccessfulAtByType();
        assertSame(
            (new DateTimeImmutable(self::SUCCESS_AT, new DateTimeZone('UTC')))->getTimestamp(),
            $map[Order58SyncType::Rules->value]->getTimestamp(),
        );
    }

    private function insertRun(string $status, string $completedAt): void
    {
        $ts = DbDateTime::format(new DateTimeImmutable('2035-01-01 00:00:00', new DateTimeZone('UTC')));
        $this->connection->createCommand()->insert('{{%integration_sync_runs}}', [
            'type' => Order58SyncType::Rules->value,
            'status' => $status,
            'attempts' => 1,
            'requested_by_admin_id' => self::ADMIN,
            'completed_at' => $completedAt,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%integration_sync_runs}}', ['requested_by_admin_id' => self::ADMIN]);
    }
}
