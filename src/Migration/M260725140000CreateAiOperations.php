<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Durable ledger for non-idempotent OpenAI operations (create vector store, upload file, attach file).
 *
 * `Idempotency-Key` is sent as best-effort but is never trusted for correctness. Instead, each such
 * operation has a row here keyed by a deterministic `operation_key`. The state machine —
 * pending → in_flight → (succeeded | needs_reconcile | failed) — lets a crashed or ambiguously-failed
 * operation be resolved by querying OpenAI and adopting any object that was actually created, so a retry
 * can never silently produce a duplicate store or file.
 *
 * Populated and driven from Phase 6; created here alongside the gateway it protects.
 */
final class M260725140000CreateAiOperations implements RevertibleMigrationInterface
{
    private const TABLE = '{{%ai_operations}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::TABLE,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                // Deterministic identity, e.g. "vs.create:kb:12" or "file.upload:indexed_file:88". The
                // unique index makes "begin an operation" idempotent regardless of retries.
                'operation_key' => ColumnBuilder::string(191)->notNull(),
                'type' => ColumnBuilder::string(64)->notNull(),
                'subject_type' => ColumnBuilder::string(32)->notNull(),
                'subject_id' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'status' => ColumnBuilder::enum(
                    ['pending', 'in_flight', 'succeeded', 'needs_reconcile', 'failed'],
                )->notNull()->defaultValue('pending'),
                // A digest of the request, so a reconcile can tell a genuine match from a coincidence.
                'request_fingerprint' => ColumnBuilder::char(64)->null(),
                'idempotency_key' => ColumnBuilder::char(36)->null(),
                // The resulting provider id (vector store id / file id) once the operation succeeds.
                'result_id' => ColumnBuilder::string(64)->null(),
                'attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
                'next_attempt_at' => ColumnBuilder::datetime()->null(),
                'last_error_code' => ColumnBuilder::string(64)->null(),
                'last_error_message' => ColumnBuilder::string(1000)->null(),
                'started_at' => ColumnBuilder::datetime()->null(),
                'completed_at' => ColumnBuilder::datetime()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->createIndex(self::TABLE, 'ux_ai_operations_key', 'operation_key', IndexType::UNIQUE);
        // The reconciler's queue: operations awaiting resolution, oldest eligible first.
        $b->createIndex(self::TABLE, 'ix_ai_operations_status', ['status', 'next_attempt_at', 'id']);
        $b->createIndex(self::TABLE, 'ix_ai_operations_subject', ['subject_type', 'subject_id']);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
