<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Constant\IndexType;
use Yiisoft\Db\Constant\ReferentialAction;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Documents uploaded to a knowledge base, and the two child tables that track their indexing and
 * processing history.
 *
 * Uploads only ever reach `status = 'queued'` in the web request; the background worker (Phase 6)
 * drives them through processing to `ready`. The columns for that lifecycle (attempts, claim marker,
 * backoff, errors) are created here so no later migration is needed to start the worker.
 */
final class M260725120000CreateDocuments implements RevertibleMigrationInterface
{
    private const DOCUMENTS = '{{%documents}}';
    private const INDEX_FILES = '{{%document_index_files}}';
    private const EVENTS = '{{%document_processing_events}}';
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            self::DOCUMENTS,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'knowledge_base_id' => ColumnBuilder::bigint()->notNull(),
                // Display only. Always HTML-escaped when rendered; never used as a filesystem path.
                'original_filename' => ColumnBuilder::string(255)->notNull(),
                // Location on disk, RELATIVE to the storage root. Never rendered to a user.
                'stored_path' => ColumnBuilder::string(512)->notNull(),
                // The random token embedded in the stored filename and the OpenAI upload name, so a
                // reconciliation (Phase 5) can match a remote object back to this row deterministically.
                'storage_token' => ColumnBuilder::char(32)->notNull(),
                // Server-detected MIME type — never the browser-supplied one.
                'mime_type' => ColumnBuilder::string(128)->notNull(),
                'extension' => ColumnBuilder::string(16)->notNull(),
                'size_bytes' => ColumnBuilder::bigint()->unsigned()->notNull(),
                'checksum_sha256' => ColumnBuilder::char(64)->notNull(),
                // 'pdf' | 'image' today. VARCHAR, not ENUM, so a future processor (docx, txt) needs no
                // migration — only a new processor class.
                'kind' => ColumnBuilder::string(32)->notNull(),
                'status' => ColumnBuilder::enum(
                    ['uploaded', 'queued', 'processing', 'indexing', 'ready', 'failed', 'deleted'],
                )->notNull()->defaultValue('uploaded'),
                // "Process now" raises this so the worker picks the document ahead of the queue.
                'priority' => ColumnBuilder::tinyint()->unsigned()->notNull()->defaultValue(0),
                'processing_attempts' => ColumnBuilder::smallint()->unsigned()->notNull()->defaultValue(0),
                'processing_started_at' => ColumnBuilder::datetime()->null(),
                'next_attempt_at' => ColumnBuilder::datetime()->null(),
                // Redacted and length-capped before storage.
                'error_code' => ColumnBuilder::string(64)->null(),
                'error_message' => ColumnBuilder::string(1000)->null(),
                'processed_at' => ColumnBuilder::datetime()->null(),
                'deleted_at' => ColumnBuilder::datetime()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::TABLE_OPTIONS,
        );

        $b->addForeignKey(
            self::DOCUMENTS,
            'fk_documents_knowledge_base',
            'knowledge_base_id',
            '{{%knowledge_bases}}',
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        // Duplicate detection among LIVE documents, enforced by the database rather than a racy
        // application check. `dedupe_hash` is the checksum for a live document and NULL for a deleted
        // one; because MySQL treats NULLs as distinct in a unique index, re-uploading a file that was
        // previously deleted is allowed, while a live duplicate is rejected. MigrationBuilder has no API
        // for a generated column, so this is the one place raw SQL is used. Table prefix is empty in
        // this application, so the literal table name is safe here.
        $b->execute(
            'ALTER TABLE `documents`'
            . ' ADD COLUMN `dedupe_hash` CHAR(64)'
            . " AS (IF(`status` = 'deleted', NULL, `checksum_sha256`)) STORED",
        );
        $b->execute(
            'ALTER TABLE `documents`'
            . ' ADD UNIQUE INDEX `ux_documents_dedupe` (`knowledge_base_id`, `dedupe_hash`)',
        );

        // Document list for one knowledge base, filtered by status, newest first.
        $b->createIndex(self::DOCUMENTS, 'ix_documents_kb_status', ['knowledge_base_id', 'status', 'created_at']);
        // The worker's claim query: eligible documents by priority then backoff.
        $b->createIndex(self::DOCUMENTS, 'ix_documents_queue', ['status', 'priority', 'next_attempt_at', 'id']);

        $this->createIndexFiles($b);
        $this->createEvents($b);
    }

    public function down(MigrationBuilder $b): void
    {
        // Children first (they reference documents), then the parent.
        $b->dropTable(self::EVENTS);
        $b->dropTable(self::INDEX_FILES);
        $b->dropTable(self::DOCUMENTS);
    }

    /**
     * The OpenAI artifacts derived from a document. A text PDF yields one `source` row; an image or a
     * scanned PDF yields a `derived_markdown` row. Kept in a child table (not flattened onto documents)
     * so a citation's file id resolves to the original document — and thus the original filename —
     * regardless of how many artifacts were produced. Populated from Phase 5.
     */
    private function createIndexFiles(MigrationBuilder $b): void
    {
        $b->createTable(
            self::INDEX_FILES,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'document_id' => ColumnBuilder::bigint()->notNull(),
                'role' => ColumnBuilder::enum(['source', 'derived_markdown'])->notNull(),
                // The generated Markdown path for a derived artifact; NULL for a source file.
                'derived_path' => ColumnBuilder::string(512)->null(),
                'openai_file_id' => ColumnBuilder::string(64)->null(),
                'index_status' => ColumnBuilder::enum(
                    ['pending', 'in_progress', 'completed', 'failed', 'cancelled'],
                )->notNull()->defaultValue('pending'),
                'usage_bytes' => ColumnBuilder::bigint()->unsigned()->null(),
                'last_error_code' => ColumnBuilder::string(64)->null(),
                'last_error_message' => ColumnBuilder::string(1000)->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
                'updated_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::TABLE_OPTIONS,
        );

        $b->addForeignKey(
            self::INDEX_FILES,
            'fk_index_files_document',
            'document_id',
            '{{%documents}}',
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        // One OpenAI file id maps to exactly one artifact, so a citation resolves unambiguously. NULLs
        // are distinct, so many not-yet-uploaded rows may coexist.
        $b->createIndex(self::INDEX_FILES, 'ux_index_files_openai', 'openai_file_id', IndexType::UNIQUE);
        $b->createIndex(self::INDEX_FILES, 'ix_index_files_document', ['document_id', 'role']);
    }

    /**
     * Append-only audit of what happened to a document during processing, for the "view processing
     * errors" requirement. Messages are redacted before they are written.
     */
    private function createEvents(MigrationBuilder $b): void
    {
        $b->createTable(
            self::EVENTS,
            [
                'id' => ColumnBuilder::bigPrimaryKey(),
                'document_id' => ColumnBuilder::bigint()->notNull(),
                'status' => ColumnBuilder::string(32)->notNull(),
                'message' => ColumnBuilder::string(1000)->null(),
                'metadata_json' => ColumnBuilder::json()->null(),
                'created_at' => ColumnBuilder::datetime()->notNull(),
            ],
            self::TABLE_OPTIONS,
        );

        $b->addForeignKey(
            self::EVENTS,
            'fk_events_document',
            'document_id',
            '{{%documents}}',
            'id',
            ReferentialAction::CASCADE,
            ReferentialAction::CASCADE,
        );

        $b->createIndex(self::EVENTS, 'ix_events_document', ['document_id', 'id']);
    }
}
