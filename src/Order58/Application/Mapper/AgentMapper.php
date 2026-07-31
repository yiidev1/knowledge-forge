<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Domain\AgentMirror;

/**
 * Maps an Order58 agent into an {@see AgentMirror} — safe fields only. `account_id` is carried as profile
 * data and is never treated as authorization. No credential ever appears in the Integration API response,
 * so the snapshot (source record minus `_sync_hash`) is safe to store for audit.
 */
final class AgentMapper
{
    public function toMirror(Order58Agent $agent): AgentMirror
    {
        $snapshot = $agent->raw;
        unset($snapshot['_sync_hash']);

        return new AgentMirror(
            id: null,
            adminId: $agent->adminId,
            username: $agent->username,
            firstName: $agent->firstName,
            lastName: $agent->lastName,
            email: $agent->email,
            contactNumber: $agent->contactNumber,
            role: $agent->role,
            status: $agent->status,
            userType: $agent->userType,
            accountId: $agent->accountId,
            syncHash: $agent->syncHash,
            sourceModifiedAt: $agent->modifiedAt,
            snapshot: $snapshot,
        );
    }
}
