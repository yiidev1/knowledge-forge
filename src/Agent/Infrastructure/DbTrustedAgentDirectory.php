<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\TrustedAgentDirectoryInterface;
use App\Agent\Domain\TrustedAgentLookupResult;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function count;
use function is_array;
use function trim;

/**
 * Resolves a username against the mirrored Order58 agents, for the fallback login path.
 *
 * Reads `order58_agents` directly rather than going through the Order58 module's repository: the question
 * being asked is an Agent-realm authorization question, and the answer shape is an {@see AgentIdentity}.
 * (The sibling {@see DbAgentStoreDirectory} reads `knowledge_bases` for the same reason.) Read-only — this
 * class never writes.
 *
 * `LIMIT 2` is the point of the query. `order58_agents` has a unique index on `admin_id` but **none** on
 * `username`, and real collisions exist upstream; the `user_type`/`status` predicate happens to resolve all
 * of them today, but that is a property of the data, not a constraint. Fetching two rows makes ambiguity
 * observable so it can be refused instead of silently resolving to whichever row the engine returned first.
 */
final readonly class DbTrustedAgentDirectory implements TrustedAgentDirectoryInterface
{
    private const TABLE = '{{%order58_agents}}';

    private const REQUIRED_USER_TYPE = 'agent';
    private const REQUIRED_STATUS = 'active';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findActiveAgentByUsername(
        string $username,
        DateTimeImmutable $notSyncedBefore,
    ): TrustedAgentLookupResult {
        $username = trim($username);
        if ($username === '') {
            return TrustedAgentLookupResult::notFound();
        }

        $rows = $this->connection
            ->createQuery()
            ->select([
                'admin_id',
                'username',
                'first_name',
                'last_name',
                'email_address',
                'status',
                'user_type',
                'synced_at',
            ])
            ->from(self::TABLE)
            ->where([
                'username' => $username,
                'user_type' => self::REQUIRED_USER_TYPE,
                'status' => self::REQUIRED_STATUS,
            ])
            ->limit(2)
            ->all();

        if (count($rows) > 1) {
            return TrustedAgentLookupResult::ambiguous();
        }

        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return TrustedAgentLookupResult::notFound();
        }

        // A row never touched by a sync run cannot vouch for anyone.
        $syncedAt = DbDateTime::parseNullable(self::nullableString($row['synced_at'] ?? null));
        if ($syncedAt === null || $syncedAt < $notSyncedBefore) {
            return TrustedAgentLookupResult::stale();
        }

        return TrustedAgentLookupResult::found($this->toIdentity($row));
    }

    /**
     * Builds the same identity shape the primary path produces, including its display-name rule, so nothing
     * downstream can tell which path signed the agent in.
     *
     * @param array<array-key, mixed> $row
     */
    private function toIdentity(array $row): AgentIdentity
    {
        $first = self::nullableString($row['first_name'] ?? null) ?? '';
        $last = self::nullableString($row['last_name'] ?? null) ?? '';
        $name = trim($first . ' ' . $last);
        $username = (string) $row['username'];

        return new AgentIdentity(
            adminId: (int) $row['admin_id'],
            username: $username,
            displayName: $name === '' ? $username : $name,
            email: self::nullableString($row['email_address'] ?? null),
            status: (string) $row['status'],
            userType: (string) $row['user_type'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
