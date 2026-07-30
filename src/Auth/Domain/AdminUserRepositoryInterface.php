<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Persistence boundary for administrator accounts.
 *
 * The domain depends only on this interface; the MySQL implementation lives in Infrastructure. That is
 * what keeps "add multiple users later" or "move to an external identity provider" from touching the
 * login logic.
 */
interface AdminUserRepositoryInterface
{
    public function findByUsername(string $username): ?AdminUser;

    public function findById(int $id): ?AdminUser;

    /**
     * @return int The id of the newly created account.
     */
    public function create(string $username, string $passwordHash): int;

    public function updatePasswordHash(int $id, string $newHash): void;

    public function recordLogin(int $id): void;

    public function usernameExists(string $username): bool;

    public function countAll(): int;
}
