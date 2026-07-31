<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * A mirrored Order58 agent — safe fields only, never a credential. `accountId` is employer/profile data
 * and is never used to authorize store access. `userType`/`status` are retained so the Phase 2 agent
 * login can admit only `user_type == 'agent'`.
 */
final readonly class AgentMirror
{
    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function __construct(
        public ?int $id,
        public int $adminId,
        public string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $contactNumber,
        public ?string $role,
        public string $status,
        public string $userType,
        public ?int $accountId,
        public string $syncHash,
        public ?DateTimeImmutable $sourceModifiedAt,
        public array $snapshot,
        public ?DateTimeImmutable $syncedAt = null,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function displayName(): string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? $this->username : $name;
    }
}
