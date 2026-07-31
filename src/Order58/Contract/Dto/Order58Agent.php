<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

use DateTimeImmutable;

/**
 * A safe agent profile, from Order58 `st_admin_user` records. Contains NO credentials — the Integration
 * API never returns them and Knowledge Forge never stores them.
 *
 * The stable source identity is `admin_id`. `accountId` is employer/profile data only: it is mirrored for
 * display but is never used to authorize store access. `userType`/`status` are stored so Phase 2 can gate
 * the agent login (only `user_type == agent`, `status == active`).
 */
final readonly class Order58Agent
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
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
        public ?DateTimeImmutable $modifiedAt,
        public string $syncHash,
        public array $raw,
    ) {}
}
