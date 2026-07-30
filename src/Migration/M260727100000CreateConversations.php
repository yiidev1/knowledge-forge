<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\ReferentialAction;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Chat conversations and their messages (Phase 7).
 *
 * A conversation belongs to one knowledge base; a message belongs to one conversation. Citations and
 * token usage are stored as JSON alongside the message — they are always read with the message and never
 * queried across messages in v1, so a separate table would buy nothing. The resolved `document_id` is
 * kept inside the citation JSON so rendering never re-queries. `is_grounded` / `retrieval_status` record
 * the grounding verdict for every assistant message, whether it was answered or fell back.
 */
final class M260727100000CreateConversations implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = '{{%conversations}}';
    private const MESSAGES = '{{%messages}}';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::CONVERSATIONS,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'knowledge_base_id' => ColumnBuilder::bigint()->notNull(),
                'title' => ColumnBuilder::string(255)->notNull(),
                'last_message_at' => ColumnBuilder::datetime()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::TABLE_OPTIONS,
        );

        $b->addForeignKey(
            self::CONVERSATIONS,
            'fk_conversations_knowledge_base',
            'knowledge_base_id',
            '{{%knowledge_bases}}',
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        // Newest activity first within a knowledge base — the conversation list order.
        $b->createIndex(self::CONVERSATIONS, 'ix_conversations_kb_activity', ['knowledge_base_id', 'last_message_at', 'id']);

        $b->createTable(
            self::MESSAGES,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'conversation_id' => ColumnBuilder::bigint()->notNull(),
                'role' => ColumnBuilder::enum(['user', 'assistant'])->notNull(),
                'content' => ColumnBuilder::text()->notNull(),
                'citations_json' => ColumnBuilder::json()->null(),
                'usage_json' => ColumnBuilder::json()->null(),
                // A retrieval was verified and at least one citation resolved.
                'is_grounded' => ColumnBuilder::boolean()->notNull()->defaultValue(false),
                // file_search_call status, or 'not_called' — the audit trail for the grounding decision.
                'retrieval_status' => ColumnBuilder::string(32)->null(),
                'openai_response_id' => ColumnBuilder::string(64)->null(),
                'model' => ColumnBuilder::string(64)->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::TABLE_OPTIONS,
        );

        $b->addForeignKey(
            self::MESSAGES,
            'fk_messages_conversation',
            'conversation_id',
            self::CONVERSATIONS,
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        $b->createIndex(self::MESSAGES, 'ix_messages_conversation', ['conversation_id', 'id']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::MESSAGES);
        $b->dropTable(self::CONVERSATIONS);
    }
}
