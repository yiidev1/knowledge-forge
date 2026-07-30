<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use PDO;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

/**
 * Builds the database connection.
 *
 * This exists as a service rather than as an inline DI closure so that the health command can attempt a
 * connection through exactly the same code path the application uses. A health check that builds its
 * own connection can report "healthy" while the real one is misconfigured.
 */
final readonly class DbConnectionFactory
{
    public function __construct(
        private DbParams $params,
        private SchemaCache $schemaCache,
    ) {}

    public function create(): Connection
    {
        $this->params->assertComplete();

        $driver = new Driver(
            $this->params->dsn(),
            $this->params->user,
            $this->params->password->reveal(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Rows come back as strings and are mapped explicitly by the repositories, so an
                // implicit numeric conversion can never quietly alter a checksum or a large id.
                PDO::ATTR_STRINGIFY_FETCHES => true,
                // ATTR_EMULATE_PREPARES is deliberately left at the PDO default (on) for MySQL. Turning
                // it off breaks yiisoft/db: its schema-introspection queries reuse the same named
                // placeholder twice, which the native prepared-statement protocol cannot express
                // ("SQLSTATE[HY093] Invalid parameter number"). Emulated prepares still escape values
                // through the driver with the connection charset pinned to utf8mb4 below, and every
                // query in this application is parameter-bound, so this is not a injection trade-off.
                // Every timestamp in this application is UTC. Pinning the session zone means a value
                // written by PHP-FPM and read by the cron worker denotes the same instant even if the
                // two processes disagree about the server's local zone. STRICT_ALL_TABLES turns silent
                // truncation of an over-long filename or error message into an error.
                PDO::MYSQL_ATTR_INIT_COMMAND
                    => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ],
        );

        $driver->charset($this->params->charset);

        return new Connection($driver, $this->schemaCache);
    }
}
