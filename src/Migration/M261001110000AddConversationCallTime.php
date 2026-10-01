<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Lets an upload record when the call it came from actually happened.
 *
 * ## The fact that was missing
 *
 * The store page has one timestamp per order and it is `created_at` — the moment this application wrote
 * the row. For a recording imported from the provider that is the *download* time, which can be hours or
 * days after the call, and nothing on the page said so. Two different facts were being shown as one.
 *
 * The provider does publish a call time. It reaches the import side and is recorded there, but the audio
 * tables could not see it: there is no join, and the module that owns them may not name the module that
 * owns the other. So the value crosses the same seam `call_session_id` already crosses — handed over at
 * ingestion, stored here, and read by the page that needs it.
 *
 * ## Why a string, and not a DATETIME
 *
 * **The provider does not document its timezone.** Parsing it into a DATETIME means choosing one, and
 * choosing wrong makes every call time silently four or five hours out — the kind of error that looks
 * like data rather than a bug. The column therefore holds exactly what the provider sent, the screen
 * prints exactly that, and no zone label is appended to a value that carries none.
 *
 * That is the same decision, for the same reason, that the import side made when it recorded this value
 * verbatim rather than parsing it.
 *
 * ## Why nullable, and nothing back-filled
 *
 * A recording uploaded by hand has no call time and never will; so does every row that predates this.
 * NULL is the honest answer for both, and the page shows the import time alone for them — which is what
 * it showed for everything before today.
 */
final class M261001110000AddConversationCallTime implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';

    /** The provider's own column width for the same value. */
    private const LENGTH = 64;

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD COLUMN `call_time_raw` VARCHAR(' . self::LENGTH . ') NULL AFTER `call_session_id`',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * This is the only place the audio side knows when a call happened; the import row it came from can
     * be purged independently. Dropping the column would leave the store page unable to tell a call time
     * from a download time again.
     */
    public function down(MigrationBuilder $b): void
    {
        $known = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::CONVERSATIONS . '` WHERE `call_time_raw` IS NOT NULL',
        )->queryScalar();

        if ($known > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $known . ' upload(s) record when their call happened, and this '
                . 'column is the only record of it on this side. Export it, or clear those values first.',
            );
        }

        $b->execute('ALTER TABLE `' . self::CONVERSATIONS . '` DROP COLUMN `call_time_raw`');
    }
}
