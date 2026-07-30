<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Environment;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use PHPUnit\Framework\Assert;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Throwable;

/**
 * Builds a real database connection for integration tests through the same factory the application
 * uses, so a test exercises the production connection code rather than a lookalike.
 *
 * When the database is not configured or not reachable, {@see connectOrSkip()} skips the calling test
 * rather than failing it: the unit suite must stay runnable on a machine with no database.
 */
final class IntegrationDb
{
    private static ?ConnectionInterface $connection = null;

    public static function connectOrSkip(): ConnectionInterface
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $params = new DbParams(
            host: Environment::string('DB_HOST'),
            port: Environment::int('DB_PORT'),
            name: Environment::string('DB_NAME'),
            user: Environment::string('DB_USER'),
            password: Environment::string('DB_PASSWORD'),
            charset: Environment::string('DB_CHARSET'),
            socket: Environment::string('DB_SOCKET'),
        );

        if (!$params->isComplete()) {
            Assert::markTestSkipped('Database is not configured (set DB_* in .env) — skipping integration test.');
        }

        try {
            $connection = (new DbConnectionFactory($params, new SchemaCache(new ArrayCache())))->create();
            $connection->createCommand('SELECT 1')->execute();
        } catch (Throwable $e) {
            Assert::markTestSkipped('Database is not reachable — skipping integration test: ' . $e->getMessage());
        }

        return self::$connection = $connection;
    }

    /**
     * Removes rows a test created, keyed by a distinctive marker, so integration tests leave no residue
     * and can be re-run. Truncation is avoided so unrelated data on a shared dev database survives.
     */
    public static function cleanup(ConnectionInterface $connection, string $table, array $condition): void
    {
        $connection->createCommand()->delete($table, $condition)->execute();
    }
}
