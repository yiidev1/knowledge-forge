<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Remember when the provider said to come back.
 *
 * ## Why a column, and not a setting
 *
 * The application's own cooldown no longer rations a manual synchronisation: an administrator who can
 * see the data is stale may press the button as often as they like. That removes a limit this
 * application invented. It removes nothing the provider imposes.
 *
 * So the one restriction that still binds is the one the provider states itself — HTTP 429, with or
 * without a `Retry-After`. That is a fact about a moment in time, not a configured policy, so it is
 * recorded on the run that received it and honoured against every later caller, manual included.
 * Keeping it in configuration would mean an operator could edit away somebody else's rate limit.
 *
 * ## Nullable, and almost always null
 *
 * Only a refused request writes it. A successful run leaves it NULL, which is why this is a column on
 * the run rather than a table of its own: the answer to "may I ask now?" is read from the most recent
 * run either way, and a second table would be a second thing to join for a value that is usually
 * absent.
 *
 * ## Nothing is deleted
 *
 * One nullable column is added. Every existing run keeps its id and every other value.
 */
final class M261026200000CaseOrderSyncRunsProviderRetryAfter implements RevertibleMigrationInterface
{
    private const TABLE = '{{%order58_case_order_sync_runs}}';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::TABLE . ' '
            // When the provider said it would accept another request. NULL means it never said.
            . 'ADD COLUMN `provider_retry_after` DATETIME NULL AFTER `http_status`',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        /*
         * Dropping this forgets a live restriction.
         *
         * If a provider has told us to wait and that instant has not passed, removing the column
         * would let the next press go straight out. So the rollback refuses while any run still
         * carries one, exactly as the run-id migration refuses rather than destroying history.
         */
        $pending = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' '
            . 'WHERE `provider_retry_after` IS NOT NULL AND `provider_retry_after` > UTC_TIMESTAMP()',
        )->queryScalar();

        if ($pending > 0) {
            throw new \RuntimeException(
                'Refusing to drop provider_retry_after: ' . $pending . ' run(s) still hold a retry '
                . 'instruction from the provider that has not yet elapsed.',
            );
        }

        $b->execute('ALTER TABLE ' . self::TABLE . ' DROP COLUMN `provider_retry_after`');
    }
}
