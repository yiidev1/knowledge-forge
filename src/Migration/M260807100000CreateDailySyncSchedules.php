<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Durable per-New-York-calendar-day idempotency for the daily Order58 schedulers (Knowledge 02:00, Rules 03:00).
 *
 * The row is a *reservation*: `UNIQUE(sync_type, ny_date)` means two invocations on the same NY date collide, and
 * a row only counts as "done" once `status = 'enqueued'`. A crash between reserving and enqueuing leaves it
 * `pending`/`failed` so a later catch-up run retries it, while a row that reached `enqueued` guarantees at most
 * one successful scheduled enqueue per type per day. Manual admin syncs never touch this table.
 *
 * `ny_date` is a plain DATE (the application-zone calendar date computed via APP_TIMEZONE); DB timestamps stay UTC.
 */
final class M260807100000CreateDailySyncSchedules implements RevertibleMigrationInterface
{
    private const TABLE = '{{%order58_daily_sync_schedules}}';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::bigPrimaryKey(),
            'sync_type' => ColumnBuilder::string(32)->notNull(),
            'ny_date' => ColumnBuilder::date()->notNull(),
            'status' => ColumnBuilder::enum(['pending', 'enqueued', 'failed'])->notNull()->defaultValue('pending'),
            'integration_sync_run_id' => ColumnBuilder::bigint()->null(),
            'attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
            'last_error' => ColumnBuilder::string(1000)->null(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], self::TABLE_OPTIONS);

        // One reservation per (type, NY date) — the durable daily idempotency guard.
        $b->createIndex(self::TABLE, 'ux_daily_sync_type_date', ['sync_type', 'ny_date'], IndexType::UNIQUE);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
