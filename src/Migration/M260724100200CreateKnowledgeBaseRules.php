<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Constant\ReferentialAction;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Per-knowledge-base answering rules, applied in priority order.
 *
 * Rules are provider-neutral by design: they are plain text held here and rendered into a prompt by the
 * instruction builder. Nothing about this table knows that OpenAI exists, so replacing the AI provider
 * leaves an administrator's rules untouched.
 */
final class M260724100200CreateKnowledgeBaseRules implements RevertibleMigrationInterface
{
    private const TABLE = '{{%knowledge_base_rules}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // Signed, matching bigPrimaryKey(). MySQL refuses a foreign key whose signedness
                // differs from the referenced column, and the framework's primary keys are signed.
                'knowledge_base_id' => ColumnBuilder::bigint()->notNull(),
                'name' => ColumnBuilder::string(160)->notNull(),
                'instruction' => ColumnBuilder::text()->notNull(),
                // Lower number wins. Gaps are intentional so a rule can be inserted between two others
                // without renumbering the rest.
                'priority' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(100),
                // TINYINT(1) rather than the driver's BIT(1) boolean, so the flag round-trips as a
                // plain "0"/"1" string through PDO. See the note in the admin-users migration.
                'is_enabled' => ColumnBuilder::tinyint(1)->notNull()->defaultValue(1),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            $this->tableOptions(),
        );

        // Deleting a knowledge base takes its rules with it; they have no meaning on their own.
        $b->addForeignKey(
            self::TABLE,
            'fk_knowledge_base_rules_knowledge_base',
            'knowledge_base_id',
            '{{%knowledge_bases}}',
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        // Rule names are how an administrator identifies a rule in the UI, so they must be
        // unambiguous within a knowledge base — though two knowledge bases may reuse a name.
        $b->createIndex(
            self::TABLE,
            'ux_knowledge_base_rules_name',
            ['knowledge_base_id', 'name'],
            IndexType::UNIQUE,
        );

        // Exactly matches the query the instruction builder runs before every answer:
        // enabled rules for one knowledge base, in priority order.
        $b->createIndex(
            self::TABLE,
            'ix_knowledge_base_rules_active',
            ['knowledge_base_id', 'is_enabled', 'priority', 'id'],
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }

    private function tableOptions(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    }
}
