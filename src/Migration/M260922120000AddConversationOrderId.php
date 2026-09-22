<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Lets an upload record the order it belongs to.
 *
 * ## Why it sits on the conversation
 *
 * It is a fact about the upload, given once by the person making it, and a separate Customer + Agent
 * pair is one upload with two recordings — putting it on the job would mean storing the same value
 * twice and having somewhere for the two copies to disagree.
 *
 * ## Why VARCHAR rather than BIGINT
 *
 * Nothing in this database has orders in it, so this references nothing and is never joined on: it is
 * an identifier that gets displayed, not a quantity or a key. A 20-digit order number does not fit in
 * a signed BIGINT, and a numeric column would also quietly drop a leading zero from a value somebody
 * typed. Stored as text, what comes back is what was entered.
 *
 * ## Why there is no CHECK constraint on the format
 *
 * The digits-only rule lives in the application, in the value object the upload validates through. A
 * CHECK here would refuse a legitimate future order format at 3am, as a database error nobody can act
 * on, instead of at review where the decision belongs.
 *
 * ## Why nullable, and nothing back-filled
 *
 * The field is optional by design — a recording with no order is a normal recording — so NULL is a real
 * answer and not a missing one. Adding it nullable rewrites no existing row, and every conversation
 * uploaded before today keeps working with NULL, which the history prints as an em dash.
 *
 * ## Raw SQL
 *
 * One ALTER, to match the style of the columns already added to this table.
 */
final class M260922120000AddConversationOrderId implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';

    /** Twenty digits is what the application accepts; this leaves room to spare. */
    private const LENGTH = 32;

    public function up(MigrationBuilder $b): void
    {
        // Nullable, so adding it rewrites no existing row and no upload is locked for the rewrite.
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD COLUMN `order_id` VARCHAR(' . self::LENGTH . ') NULL AFTER `recording_type`',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * An order id was typed by a person and is recorded in exactly one place. Dropping the column would
     * lose that outright, so this stops instead and says how much is at stake. A database where nobody
     * has used the field yet reverts cleanly.
     */
    public function down(MigrationBuilder $b): void
    {
        $withOrder = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::CONVERSATIONS . '` WHERE `order_id` IS NOT NULL',
        )->queryScalar();

        if ($withOrder > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $withOrder . ' upload(s) carry an order id, and this column '
                . 'is the only record of it. Export it, or clear those values first.',
            );
        }

        $b->execute('ALTER TABLE `' . self::CONVERSATIONS . '` DROP COLUMN `order_id`');
    }
}
