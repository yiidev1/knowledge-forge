<?php

declare(strict_types=1);

namespace App\Rules\Infrastructure;

use App\Rules\Contract\RuleClassificationEventRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function json_encode;

/**
 * MySQL-backed, append-only classification audit trail.
 */
final readonly class DbRuleClassificationEventRepository implements RuleClassificationEventRepositoryInterface
{
    private const TABLE = '{{%rule_classification_events}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function record(
        int $canonicalId,
        string $eventType,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $message,
        array $metadata,
        ?int $adminUserId,
        DateTimeImmutable $now,
    ): void {
        $this->connection->createCommand()->insert(self::TABLE, [
            'rule_catalog_rule_id' => $canonicalId,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'message' => $message,
            'metadata_json' => $metadata === [] ? null : (string) json_encode($metadata),
            'admin_user_id' => $adminUserId,
            'created_at' => DbDateTime::format($now),
        ])->execute();
    }
}
