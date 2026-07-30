<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\AdminUser;
use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;

/**
 * MySQL-backed administrator repository.
 *
 * Returns fully-typed {@see AdminUser} entities, never raw rows, so nothing above this layer depends on
 * column names or on the string types PDO hands back.
 */
final readonly class DbAdminUserRepository implements AdminUserRepositoryInterface
{
    private const TABLE = '{{%admin_users}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function findByUsername(string $username): ?AdminUser
    {
        return $this->hydrate(
            $this->connection
                ->createQuery()
                ->from(self::TABLE)
                ->where(['username' => $username])
                ->limit(1)
                ->one(),
        );
    }

    public function findById(int $id): ?AdminUser
    {
        return $this->hydrate(
            $this->connection
                ->createQuery()
                ->from(self::TABLE)
                ->where(['id' => $id])
                ->limit(1)
                ->one(),
        );
    }

    public function create(string $username, string $passwordHash): int
    {
        $now = DbDateTime::format($this->clock->now());

        $this->connection->createCommand()->insert(self::TABLE, [
            'username' => $username,
            'password_hash' => $passwordHash,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    public function updatePasswordHash(int $id, string $newHash): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['password_hash' => $newHash, 'updated_at' => DbDateTime::format($this->clock->now())],
            ['id' => $id],
        )->execute();
    }

    public function recordLogin(int $id): void
    {
        $this->connection->createCommand()->update(
            self::TABLE,
            ['last_login_at' => DbDateTime::format($this->clock->now())],
            ['id' => $id],
        )->execute();
    }

    public function usernameExists(string $username): bool
    {
        return (int) $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['username' => $username])
            ->count() > 0;
    }

    public function countAll(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->count();
    }

    private function hydrate(array|object|null $row): ?AdminUser
    {
        if (!is_array($row)) {
            return null;
        }

        return new AdminUser(
            id: (int) $row['id'],
            username: (string) $row['username'],
            passwordHash: (string) $row['password_hash'],
            // MySQL returns bit(1) as a binary string; compare loosely so both "\x01" and "1" read true.
            isActive: (bool) (int) $row['is_active'],
            lastLoginAt: DbDateTime::parseNullable(self::asNullableString($row['last_login_at'])),
            createdAt: DbDateTime::parse((string) $row['created_at']),
            updatedAt: DbDateTime::parse((string) $row['updated_at']),
        );
    }

    private static function asNullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
