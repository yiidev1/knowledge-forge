<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Administrator accounts.
 *
 * Single-tenant for now: one small table, no roles. Multi-user and permissions are a later concern and
 * will arrive as additional tables rather than as changes here.
 */
final class M260724100000CreateAdminUsers implements RevertibleMigrationInterface
{
    private const TABLE = '{{%admin_users}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'username' => ColumnBuilder::string(64)->notNull(),
                // Sized for whatever PHP's password_hash() default becomes. Today bcrypt needs 60
                // characters; argon2id needs about 96. 255 means a future algorithm upgrade is a code
                // change, not a migration.
                'password_hash' => ColumnBuilder::string(255)->notNull(),
                // TINYINT(1), not the driver's boolean() (which is BIT(1)): a BIT column comes back from
                // PDO as a raw byte "\x01" that casts to int 0, so a plain 0/1 tinyint round-trips far
                // more predictably through the string-typed fetch this application uses.
                'is_active' => ColumnBuilder::tinyint(1)->notNull()->defaultValue(1),
                'last_login_at' => ColumnBuilder::datetime()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            $this->tableOptions(),
        );

        $b->createIndex(self::TABLE, 'ux_admin_users_username', 'username', IndexType::UNIQUE);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }

    /**
     * utf8mb4 throughout: document names, questions and answers routinely contain characters outside
     * the BMP, and utf8mb3 truncates them.
     */
    private function tableOptions(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    }
}
