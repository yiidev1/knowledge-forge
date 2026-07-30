<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use App\Shared\Domain\Exception\ConfigurationException;
use App\Shared\Domain\ValueObject\SecretValue;
use SensitiveParameter;
use Yiisoft\Db\Mysql\Dsn;
use Yiisoft\Db\Mysql\DsnSocket;

/**
 * Resolved database configuration.
 *
 * Constructing this is the point at which a missing credential becomes an error: the failure happens
 * the first time something needs the database, with a message naming the variable, rather than as a
 * PDO connection error that mentions "using password: NO".
 */
final readonly class DbParams
{
    public SecretValue $password;

    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        #[SensitiveParameter]
        string $password,
        public string $charset = 'utf8mb4',
        /**
         * When non-empty the connection uses this unix socket and {@see $host}/{@see $port} are
         * ignored. Ubuntu's packaged MySQL frequently listens on a socket only.
         */
        public string $socket = '',
    ) {
        $this->password = new SecretValue($password);
    }

    public function usesSocket(): bool
    {
        return $this->socket !== '';
    }

    /**
     * True when every value needed to open a connection is present.
     *
     * Construction deliberately does not throw: `./yii kf:health` has to be able to build this object
     * in order to *report* that `DB_USER` is missing. Enforcement happens in {@see assertComplete()},
     * which the connection factory calls, so anything that actually touches the database still fails
     * immediately and with a useful message.
     */
    public function isComplete(): bool
    {
        return $this->missingVariables() === [];
    }

    /**
     * @return list<string> Names of the variables that still need a value.
     */
    public function missingVariables(): array
    {
        $missing = [];

        // Either transport will do, but one of them must be configured.
        if ($this->host === '' && $this->socket === '') {
            $missing[] = 'DB_HOST';
        }

        if ($this->name === '') {
            $missing[] = 'DB_NAME';
        }

        if ($this->user === '') {
            $missing[] = 'DB_USER';
        }

        return $missing;
    }

    /**
     * @throws ConfigurationException when a value required to open a connection is absent.
     */
    public function assertComplete(): void
    {
        if ($this->host === '' && $this->socket === '') {
            throw ConfigurationException::missing('DB_HOST (or DB_SOCKET)', 'connect to the database');
        }

        if ($this->name === '') {
            throw ConfigurationException::missing('DB_NAME', 'select the application database');
        }

        if ($this->user === '') {
            throw ConfigurationException::missing('DB_USER', 'authenticate against the database');
        }
    }

    /**
     * The connection charset is pinned in the DSN so the driver does not fall back to a server default
     * that cannot represent the full range of characters found in uploaded documents.
     */
    public function dsn(): Dsn|DsnSocket
    {
        if ($this->usesSocket()) {
            return new DsnSocket(
                driver: 'mysql',
                unixSocket: $this->socket,
                databaseName: $this->name,
                options: ['charset' => $this->charset],
            );
        }

        return new Dsn(
            driver: 'mysql',
            host: $this->host,
            databaseName: $this->name,
            port: (string) $this->port,
            options: ['charset' => $this->charset],
        );
    }

    /**
     * Connection description with no credentials in it, for log records and health output.
     */
    public function describe(): string
    {
        return $this->usesSocket()
            ? sprintf('mysql://%s@unix(%s)/%s', $this->user, $this->socket, $this->name)
            : sprintf('mysql://%s@%s:%d/%s', $this->user, $this->host, $this->port, $this->name);
    }
}
