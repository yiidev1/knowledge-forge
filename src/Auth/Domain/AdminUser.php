<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use DateTimeImmutable;

/**
 * An administrator account.
 *
 * The password hash is held here but is never exposed to the web layer: it exists so the login flow
 * can verify a candidate password and, when the stored algorithm is out of date, replace it. Nothing
 * renders an AdminUser directly, so the hash cannot reach a template.
 */
final class AdminUser
{
    public function __construct(
        private readonly int $id,
        private readonly string $username,
        private string $passwordHash,
        private readonly bool $isActive,
        private readonly ?DateTimeImmutable $lastLoginAt,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * A deactivated account keeps its row (for audit) but can never authenticate. Checked during login
     * so that disabling an administrator takes effect immediately, without deleting history.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function lastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Adopt a freshly computed hash after a transparent rehash. Kept in sync with the value the
     * repository persists, so the in-memory entity does not lie about what is stored.
     */
    public function replacePasswordHash(string $newHash): void
    {
        $this->passwordHash = $newHash;
    }
}
