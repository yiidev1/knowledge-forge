<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

use function sprintf;

/**
 * Admits the OPERATION realm to Order Testing attempts.
 *
 * ## One constraint, nothing else
 *
 * `order_testing_attempts` was built polymorphic from the start — `initiated_by_type` beside
 * `initiated_by_id`, indexed together as `ix_order_testing_attempts_actor`, with no foreign key,
 * precisely so a second realm could be admitted later without reshaping the table. The original
 * migration's own note says widening would be "one ALTER and one case". This is that ALTER.
 *
 * So there is no new column, no backfill and no touched row. Every existing attempt keeps the exact
 * `('ADMIN', admin_users.id)` pair it already had, and existing queries are unaffected because the
 * column's type, index and nullability are all unchanged.
 *
 * ## Why the realm is stored rather than inferred
 *
 * `admin_users.id = 7` and Order58's `admin_id = 7` are different people. Recording only the number
 * would attribute one realm's work to the other's user the day a second realm was allowed in, and that
 * is not a mistake that can be undone afterwards — nothing in the row would say which was meant.
 *
 * OPERATION ids are Order58's own `admin_id`: the value that survives a rebuild of the `order58_agents`
 * mirror, never `order58_agents.id`, which is a local key that would silently re-point.
 *
 * ## Dropping and re-adding is the only way
 *
 * MySQL has no "alter check constraint". The window between the two statements is a single DDL
 * transaction in 8.0, and in it the column is merely unconstrained — never wrong — so a concurrent
 * insert can only write a value the *new* constraint would also accept.
 */
final class M261012120000AllowOperationInitiator implements RevertibleMigrationInterface
{
    private const ATTEMPTS = '{{%order_testing_attempts}}';
    private const CONSTRAINT = 'chk_order_testing_attempts_type';

    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE ' . self::ATTEMPTS . ' DROP CHECK `' . self::CONSTRAINT . '`');
        $b->execute(
            'ALTER TABLE ' . self::ATTEMPTS . ' ADD CONSTRAINT `' . self::CONSTRAINT . '` '
            . 'CHECK (`initiated_by_type` IN (\'ADMIN\',\'OPERATION\'))',
        );
    }

    /**
     * Refuses while any operation-attributed attempt exists.
     *
     * Narrowing the constraint under rows it forbids is the destructive direction: MySQL would reject
     * the statement, or — worse, had the rows been removed first — the attempts would be gone. A test
     * somebody ran is still a record of who tested what, so this stops and says so instead of deciding
     * on the operator's behalf. Settle or remove those attempts deliberately, then revert.
     *
     * Same posture as `M261009160000CreateItemSyncBatches::down()`, which refuses while a batch is
     * still RUNNING.
     */
    public function down(MigrationBuilder $b): void
    {
        $attributed = (int) $b->getDb()
            ->createCommand(
                'SELECT COUNT(*) FROM ' . self::ATTEMPTS . ' WHERE `initiated_by_type` = \'OPERATION\'',
            )
            ->queryScalar();

        if ($attributed > 0) {
            throw new RuntimeException(sprintf(
                '%d Order Testing attempt(s) are attributed to the OPERATION realm. Reverting would '
                . 'narrow the constraint under rows it forbids and lose who ran those tests. Remove or '
                . 're-attribute them deliberately first.',
                $attributed,
            ));
        }

        $b->execute('ALTER TABLE ' . self::ATTEMPTS . ' DROP CHECK `' . self::CONSTRAINT . '`');
        $b->execute(
            'ALTER TABLE ' . self::ATTEMPTS . ' ADD CONSTRAINT `' . self::CONSTRAINT . '` '
            . 'CHECK (`initiated_by_type` IN (\'ADMIN\'))',
        );
    }
}
