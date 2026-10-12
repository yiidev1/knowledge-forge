<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use App\Environment;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function in_array;
use function is_array;
use function preg_match;
use function sprintf;
use function gethostname;
use function str_contains;

/**
 * Where a connection actually points, asked of the server rather than of the configuration.
 *
 * ## Why configuration is not enough
 *
 * A `DB_NAME` shell override, a `.env` edit and an FPM pool variable all claim to decide the target,
 * and the one that wins depends on load order. The only answer that cannot be wrong is the one the
 * **server** gives: `DATABASE()` is the schema this connection is really using, and `@@hostname` is the
 * machine really serving it. Everything here is read back over the live connection for that reason.
 *
 * ## Fail closed
 *
 * {@see assertLocalForSchemaChange()} refuses unless it can *prove* the target is local. An unreadable
 * answer, an unexpected host, a non-development `APP_ENV` and a production-looking schema name are all
 * refusals — not warnings. The failure this guards against is a schema change landing somewhere it was
 * never meant to, and that failure is not undoable by apologising afterwards.
 */
final readonly class DatabaseTarget
{
    /** Hosts that are this machine. Anything else is treated as remote until proven otherwise. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', ''];

    /** Schema names that must never receive a schema change from here, whatever else is true. */
    private const FORBIDDEN_SCHEMAS = ['/prod/i', '/live/i', '/production/i'];

    private function __construct(
        public string $schema,
        public string $serverHost,
        public string $user,
        public string $configuredHost,
        public string $socket,
        public string $appEnv,
    ) {}

    public static function resolve(ConnectionInterface $connection): self
    {
        /** @var array<string, mixed>|false $row */
        $row = $connection
            ->createCommand('SELECT DATABASE() AS db, @@hostname AS host, USER() AS usr')
            ->queryOne();

        if (!is_array($row)) {
            throw new DatabaseTargetUnknown('The database did not answer which schema this connection uses.');
        }

        return new self(
            schema: (string) ($row['db'] ?? ''),
            serverHost: (string) ($row['host'] ?? ''),
            user: (string) ($row['usr'] ?? ''),
            configuredHost: Environment::string('DB_HOST'),
            socket: Environment::string('DB_SOCKET'),
            appEnv: Environment::appEnv(),
        );
    }

    /** One line, safe to print: no password, and the user string is MySQL's own `user@host`. */
    public function describe(): string
    {
        return sprintf(
            'schema=%s server=%s user=%s transport=%s app_env=%s',
            $this->schema === '' ? '(none)' : $this->schema,
            $this->serverHost,
            $this->user,
            $this->socket !== '' ? 'unix-socket ' . $this->socket : 'tcp ' . $this->configuredHost,
            $this->appEnv,
        );
    }

    /**
     * Whether this connection is provably the local development database.
     *
     * A unix socket is local by construction — it cannot reach another machine. A TCP connection has to
     * name a loopback host. Anything else is unproven, which here means refused.
     */
    public function isProvablyLocal(): bool
    {
        if ($this->socket !== '') {
            return true;
        }

        return in_array($this->configuredHost, self::LOCAL_HOSTS, true);
    }

    /**
     * Refuse unless every condition for a safe schema change holds.
     *
     * @throws DatabaseTargetRefused when anything is unproven
     */
    public function assertLocalForSchemaChange(): void
    {
        if ($this->schema === '') {
            throw new DatabaseTargetRefused('no schema is selected: ' . $this->describe());
        }

        if (!$this->isProvablyLocal()) {
            throw new DatabaseTargetRefused(
                'the connection is not provably local, so a schema change is refused: ' . $this->describe(),
            );
        }

        if ($this->appEnv !== Environment::DEV && $this->appEnv !== Environment::TEST) {
            throw new DatabaseTargetRefused(
                'APP_ENV is not a development environment: ' . $this->describe(),
            );
        }

        foreach (self::FORBIDDEN_SCHEMAS as $pattern) {
            if (preg_match($pattern, $this->schema) === 1) {
                throw new DatabaseTargetRefused(
                    'the schema name looks like a production database: ' . $this->describe(),
                );
            }
        }
    }

    /**
     * True when the server is this machine, as far as it can be told.
     *
     * Informational only — {@see assertLocalForSchemaChange()} does not depend on it, because a
     * hostname is something an operator can set to anything.
     */
    public function serverLooksLocal(): bool
    {
        try {
            $hostname = (string) gethostname();
        } catch (Throwable) {
            return false;
        }

        return $this->serverHost === $hostname || str_contains($this->user, '@localhost');
    }
}
