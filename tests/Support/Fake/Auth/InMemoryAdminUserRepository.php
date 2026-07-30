<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Auth;

use App\Auth\Domain\AdminUser;
use App\Auth\Domain\AdminUserRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * In-memory administrator repository for unit tests, with no database.
 *
 * Records the calls that the login flow is expected to make (password upgrade, login timestamp) so a
 * test can assert on them without inspecting a database.
 */
final class InMemoryAdminUserRepository implements AdminUserRepositoryInterface
{
    /** @var array<int, AdminUser> */
    private array $byId = [];

    /** @var array<string, int> */
    private array $idByUsername = [];

    private int $nextId = 1;

    public int $updatePasswordCalls = 0;
    public int $recordLoginCalls = 0;

    public function add(string $username, string $passwordHash, bool $isActive = true): AdminUser
    {
        $id = $this->nextId++;
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $user = new AdminUser($id, $username, $passwordHash, $isActive, null, $now, $now);
        $this->byId[$id] = $user;
        $this->idByUsername[$username] = $id;

        return $user;
    }

    public function findByUsername(string $username): ?AdminUser
    {
        $id = $this->idByUsername[$username] ?? null;

        return $id === null ? null : $this->byId[$id];
    }

    public function findById(int $id): ?AdminUser
    {
        return $this->byId[$id] ?? null;
    }

    public function create(string $username, string $passwordHash): int
    {
        return $this->add($username, $passwordHash)->id();
    }

    public function updatePasswordHash(int $id, string $newHash): void
    {
        $this->updatePasswordCalls++;
    }

    public function recordLogin(int $id): void
    {
        $this->recordLoginCalls++;
    }

    public function usernameExists(string $username): bool
    {
        return isset($this->idByUsername[$username]);
    }

    public function countAll(): int
    {
        return count($this->byId);
    }
}
