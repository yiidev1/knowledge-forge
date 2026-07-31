<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\AgentMirror;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * MySQL-backed mirror of Order58 agents (safe fields only — never a credential).
 */
final readonly class DbOrder58AgentRepository implements Order58AgentRepositoryInterface
{
    private const TABLE = '{{%order58_agents}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findSyncHash(int $adminId): ?string
    {
        $value = $this->connection
            ->createQuery()
            ->select('sync_hash')
            ->from(self::TABLE)
            ->where(['admin_id' => $adminId])
            ->scalar();

        return is_string($value) ? $value : null;
    }

    public function save(AgentMirror $agent, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $insert = [
            'admin_id' => $agent->adminId,
            'username' => $agent->username,
            'first_name' => $agent->firstName,
            'last_name' => $agent->lastName,
            'email_address' => $agent->email,
            'contact_number' => $agent->contactNumber,
            'role' => $agent->role,
            'status' => $agent->status,
            'user_type' => $agent->userType,
            'account_id' => $agent->accountId,
            'snapshot_json' => (string) json_encode($agent->snapshot),
            'sync_hash' => $agent->syncHash,
            'source_modified_at' => $agent->sourceModifiedAt === null ? null : DbDateTime::format($agent->sourceModifiedAt),
            'synced_at' => $ts,
            'last_seen_sync_run_id' => $runId,
            'created_at' => $ts,
            'updated_at' => $ts,
        ];

        $update = $insert;
        unset($update['admin_id'], $update['created_at']);

        $this->connection->createCommand()->upsert(self::TABLE, $insert, $update)->execute();
    }

    public function markSeen(int $adminId, int $runId, DateTimeImmutable $now): void
    {
        $ts = DbDateTime::format($now);

        $this->connection->createCommand()->update(
            self::TABLE,
            ['last_seen_sync_run_id' => $runId, 'synced_at' => $ts, 'updated_at' => $ts],
            ['admin_id' => $adminId],
        )->execute();
    }

    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): int
    {
        return $this->connection->createCommand()->update(
            self::TABLE,
            ['status' => 'inactive', 'updated_at' => DbDateTime::format($now)],
            [
                'and',
                ['<>', 'status', 'inactive'],
                ['or', ['last_seen_sync_run_id' => null], ['<>', 'last_seen_sync_run_id', $runId]],
            ],
        )->execute();
    }

    public function findAllForDisplay(int $limit = 500): array
    {
        $rows = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->orderBy(['synced_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $agents = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $agents[] = $this->hydrate($row);
            }
        }

        return $agents;
    }

    public function countAll(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->count();
    }

    public function countActive(): int
    {
        return (int) $this->connection->createQuery()->from(self::TABLE)->where(['status' => 'active'])->count();
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): AgentMirror
    {
        $snapshotRaw = $row['snapshot_json'] ?? null;
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

        return new AgentMirror(
            id: (int) $row['id'],
            adminId: (int) $row['admin_id'],
            username: (string) $row['username'],
            firstName: self::nullableString($row['first_name']),
            lastName: self::nullableString($row['last_name']),
            email: self::nullableString($row['email_address']),
            contactNumber: self::nullableString($row['contact_number']),
            role: self::nullableString($row['role']),
            status: (string) $row['status'],
            userType: (string) $row['user_type'],
            accountId: $row['account_id'] === null ? null : (int) $row['account_id'],
            syncHash: (string) $row['sync_hash'],
            sourceModifiedAt: DbDateTime::parseNullable(self::nullableString($row['source_modified_at'])),
            snapshot: is_array($snapshot) ? $snapshot : [],
            syncedAt: DbDateTime::parseNullable(self::nullableString($row['synced_at'])),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
