<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use InvalidArgumentException;

/**
 * Canonical conversation owner: type + positive id (local admin_users.id or Order58 agent admin_id).
 */
final readonly class ChatParticipant
{
    private function __construct(
        public ParticipantType $type,
        public int $id,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Chat participant id must be >= 1.');
        }
    }

    public static function admin(int $adminUserId): self
    {
        return new self(ParticipantType::Admin, $adminUserId);
    }

    public static function agent(int $agentAdminId): self
    {
        return new self(ParticipantType::Agent, $agentAdminId);
    }

    public function isAdmin(): bool
    {
        return $this->type === ParticipantType::Admin;
    }

    /**
     * Legacy agent_admin_id column: NULL for admin threads, agent id for agent threads.
     */
    public function legacyAgentAdminId(): ?int
    {
        return $this->isAdmin() ? null : $this->id;
    }
}
