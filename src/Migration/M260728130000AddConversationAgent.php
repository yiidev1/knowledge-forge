<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Binds a conversation to the Order58 agent who owns it.
 *
 * Admin-created conversations (the existing flow) leave this NULL; agent conversations set it, and agent
 * queries scope by it so an agent only ever sees their own threads. Nullable and additive, so the admin
 * chat is untouched.
 */
final class M260728130000AddConversationAgent implements RevertibleMigrationInterface
{
    private const TABLE = '{{%conversations}}';

    public function up(MigrationBuilder $b): void
    {
        // Order58 admin_id of the owning agent; no FK — agents live in Order58, not a local users table.
        $b->addColumn(self::TABLE, 'agent_admin_id', ColumnBuilder::bigint()->unsigned()->null());
        $b->createIndex(self::TABLE, 'ix_conversations_agent', ['agent_admin_id', 'last_message_at', 'id']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex(self::TABLE, 'ix_conversations_agent');
        $b->dropColumn(self::TABLE, 'agent_admin_id');
    }
}
