<?php

declare(strict_types=1);

namespace App\Agent\Domain;

use App\Order58\Contract\Dto\Order58Agent;

use function is_int;
use function is_string;
use function trim;

/**
 * The safe identity of a signed-in Order58 agent — the only thing Knowledge Forge keeps in the session
 * after a successful login. It carries no password, no token, and nothing that could authorize store
 * access (in particular not `account_id`).
 */
final readonly class AgentIdentity
{
    public function __construct(
        public int $adminId,
        public string $username,
        public string $displayName,
        public ?string $email,
        public string $status,
        public string $userType,
    ) {}

    public static function fromAgent(Order58Agent $agent): self
    {
        $name = trim(($agent->firstName ?? '') . ' ' . ($agent->lastName ?? ''));

        return new self(
            adminId: $agent->adminId,
            username: $agent->username,
            displayName: $name === '' ? $agent->username : $name,
            email: $agent->email,
            status: $agent->status,
            userType: $agent->userType,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'admin_id' => $this->adminId,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'email' => $this->email,
            'status' => $this->status,
            'user_type' => $this->userType,
        ];
    }

    /**
     * Rebuilds the identity from a session payload, or null if it is missing/malformed (treated as
     * "not signed in").
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $adminId = $data['admin_id'] ?? null;
        $username = $data['username'] ?? null;
        if (!is_int($adminId) || !is_string($username) || $username === '') {
            return null;
        }

        $displayName = $data['display_name'] ?? null;
        $email = $data['email'] ?? null;
        $status = $data['status'] ?? null;
        $userType = $data['user_type'] ?? null;

        return new self(
            adminId: $adminId,
            username: $username,
            displayName: is_string($displayName) && $displayName !== '' ? $displayName : $username,
            email: is_string($email) ? $email : null,
            status: is_string($status) ? $status : '',
            userType: is_string($userType) ? $userType : '',
        );
    }
}
