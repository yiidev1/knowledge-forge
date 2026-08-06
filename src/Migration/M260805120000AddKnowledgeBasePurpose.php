<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Adds `knowledge_bases.purpose` — a discriminator that hides the system-managed Common-Rules knowledge base
 * from user-facing directories while keeping it fully functional for provisioning, indexing, cleanup, health
 * and usage reconciliation.
 *
 * `store` (the default) is a normal store/admin knowledge base; `shared_rules` is the single hidden Common-Rules
 * base. Adding the column as `NOT NULL DEFAULT 'store'` backfills every existing row to `store` atomically, so
 * the value is never ambiguously NULL-vs-'store' [correction 5].
 *
 * down() refuses once a non-`store` row exists (dropping the column would lose the discriminator that keeps the
 * hidden base hidden); restore from the pre-migration mysqldump instead.
 */
final class M260805120000AddKnowledgeBasePurpose implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute("ALTER TABLE `knowledge_bases` ADD COLUMN `purpose` VARCHAR(16) NOT NULL DEFAULT 'store' AFTER `agent_enabled`");
    }

    public function down(MigrationBuilder $b): void
    {
        $special = (int) $b->getDb()->createCommand("SELECT COUNT(*) FROM `knowledge_bases` WHERE `purpose` <> 'store'")->queryScalar();
        if ($special > 0) {
            throw new RuntimeException(
                'migrate:down aborted: a system knowledge base (purpose <> \'store\') exists; dropping the column '
                . 'would lose the discriminator that hides it. Restore from the pre-migration mysqldump instead.',
            );
        }

        $b->execute('ALTER TABLE `knowledge_bases` DROP COLUMN `purpose`');
    }
}
