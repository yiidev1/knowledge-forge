<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Login throttling state.
 *
 * One row per (username, client IP) pair, identified by a salted hash so the table never stores a
 * plaintext username or address. Backing this with a table rather than a cache is deliberate: the
 * request-scoped array cache used elsewhere cannot count failures across the separate requests a
 * brute-force attempt is made of.
 */
final class M260724100300CreateAuthLoginAttempts implements RevertibleMigrationInterface
{
    private const TABLE = '{{%auth_login_attempts}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                // sha256 of "username|ip" — enough to bucket attempts without storing the identifiers.
                'attempt_key' => ColumnBuilder::char(64)->notNull(),
                'attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
                'window_started_at' => ColumnBuilder::datetime()->notNull(),
                // Set once the failure count crosses the threshold; NULL while the bucket is open.
                'locked_until' => ColumnBuilder::datetime()->null(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        // The key column is the natural primary key: every lookup is a point read on it.
        $b->addPrimaryKey(self::TABLE, 'pk_auth_login_attempts', 'attempt_key');

        // Lets a periodic cleanup drop expired rows cheaply; the table is otherwise only ever read by
        // primary key.
        $b->createIndex(self::TABLE, 'ix_auth_login_attempts_updated', 'updated_at');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
