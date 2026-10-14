<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

use function sprintf;

/**
 * Admits the AGENT realm to Order Testing attempts.
 *
 * ## One constraint, nothing else — again
 *
 * The same ALTER as {@see M261012120000AllowOperationInitiator}, for the same reason: the table was
 * built polymorphic from the start, so admitting a realm costs one constraint and no reshaping. No new
 * column, no backfill, no touched row; every existing attempt keeps the pair it already had.
 *
 * ## AGENT ids are Order58's `admin_id`, the same space OPERATION uses
 *
 * That is deliberate and it is why the realm is stored beside the id rather than inferred from it. An
 * operation user and an agent can be numbered identically — they are rows in the same mirror — so the
 * column is what tells one's work from the other's. Every query that resolves a name from an attempt
 * joins on this column as well as on the id; qualifying those joins was done as its own change, before
 * this realm existed, precisely so that admitting it could not multiply an existing mis-attribution.
 *
 * ## Dropping and re-adding is the only way
 *
 * MySQL has no "alter check constraint". The window between the two statements is a single DDL
 * transaction in 8.0, and in it the column is merely unconstrained — never wrong — so a concurrent
 * insert can only write a value the *new* constraint would also accept.
 */
final class M261014180000AllowAgentInitiator implements RevertibleMigrationInterface
{
    private const ATTEMPTS = '{{%order_testing_attempts}}';
    private const CONSTRAINT = 'chk_order_testing_attempts_type';

    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE ' . self::ATTEMPTS . ' DROP CHECK `' . self::CONSTRAINT . '`');
        $b->execute(
            'ALTER TABLE ' . self::ATTEMPTS . ' ADD CONSTRAINT `' . self::CONSTRAINT . '` '
            . 'CHECK (`initiated_by_type` IN (\'ADMIN\',\'OPERATION\',\'AGENT\'))',
        );
    }

    /**
     * Refuses while any agent-attributed attempt exists.
     *
     * Narrowing the constraint under rows it forbids is the destructive direction. A test somebody ran
     * is still a record of who tested what, so this stops and says so rather than deciding on the
     * operator's behalf — the same posture as the OPERATION migration before it.
     */
    public function down(MigrationBuilder $b): void
    {
        $attributed = (int) $b->getDb()
            ->createCommand(
                'SELECT COUNT(*) FROM ' . self::ATTEMPTS . ' WHERE `initiated_by_type` = \'AGENT\'',
            )
            ->queryScalar();

        if ($attributed > 0) {
            throw new RuntimeException(sprintf(
                '%d Order Testing attempt(s) are attributed to the AGENT realm. Reverting would narrow '
                . 'the constraint under rows it forbids and lose who ran those tests. Remove or '
                . 're-attribute them deliberately first.',
                $attributed,
            ));
        }

        $b->execute('ALTER TABLE ' . self::ATTEMPTS . ' DROP CHECK `' . self::CONSTRAINT . '`');
        $b->execute(
            'ALTER TABLE ' . self::ATTEMPTS . ' ADD CONSTRAINT `' . self::CONSTRAINT . '` '
            . 'CHECK (`initiated_by_type` IN (\'ADMIN\',\'OPERATION\'))',
        );
    }
}
