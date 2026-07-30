<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Exercises the repository against a real MySQL database. Skipped when no database is configured.
 */
final class DbAdminUserRepositoryTest extends Unit
{
    private const USERNAME = '__kf_test_admin__';

    private ConnectionInterface $connection;
    private DbAdminUserRepository $repository;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbAdminUserRepository($this->connection, new SystemClock());
        $this->removeFixture();
    }

    protected function _after(): void
    {
        $this->removeFixture();
    }

    public function testCreateAndFindRoundTrip(): void
    {
        $id = $this->repository->create(self::USERNAME, 'hash-value');

        $user = $this->repository->findById($id);
        assertNotNull($user);
        assertSame(self::USERNAME, $user->username());
        assertSame('hash-value', $user->passwordHash());
        assertTrue($user->isActive(), 'a new admin must be active');
        assertNull($user->lastLoginAt(), 'a new admin has never logged in');
    }

    public function testFindByUsername(): void
    {
        $this->repository->create(self::USERNAME, 'hash-value');

        assertNotNull($this->repository->findByUsername(self::USERNAME));
        assertNull($this->repository->findByUsername('__does_not_exist__'));
    }

    public function testUsernameExists(): void
    {
        assertFalse($this->repository->usernameExists(self::USERNAME));
        $this->repository->create(self::USERNAME, 'hash-value');
        assertTrue($this->repository->usernameExists(self::USERNAME));
    }

    public function testUpdatePasswordHashPersists(): void
    {
        $id = $this->repository->create(self::USERNAME, 'old-hash');

        $this->repository->updatePasswordHash($id, 'new-hash');

        assertSame('new-hash', $this->repository->findById($id)?->passwordHash());
    }

    public function testRecordLoginSetsTheTimestamp(): void
    {
        $id = $this->repository->create(self::USERNAME, 'hash-value');

        $this->repository->recordLogin($id);

        assertNotNull($this->repository->findById($id)?->lastLoginAt());
    }

    /**
     * The tinyint(1) is_active flag must round-trip as a real boolean, not the byte-string a bit(1)
     * column would have produced.
     */
    public function testActiveFlagRoundTripsAsBoolean(): void
    {
        $id = $this->repository->create(self::USERNAME, 'hash-value');
        $this->connection->createCommand()->update('{{%admin_users}}', ['is_active' => 0], ['id' => $id])->execute();

        assertFalse($this->repository->findById($id)?->isActive());
    }

    private function removeFixture(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
