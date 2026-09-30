<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Lets an upload record which call it is a recording of.
 *
 * ## The fact that was missing
 *
 * One call produces up to three recordings — mixed, caller, callee — and until now nothing in this
 * database said they belonged together. The nearest thing was `(store_source_id, order_id)`, which is
 * not a call identity: one order can hold several calls, nothing enforces otherwise, and the store page
 * groups by order precisely because an order is the larger unit. Pairing recordings on it would
 * eventually join two different calls, silently, and the join would look right.
 *
 * The provider does have the identity — a call session id, the value `order58_call_imports` already
 * stores and keys on. This column is where it lands on the audio side, so the two modules agree about
 * what a call is without either naming the other.
 *
 * ## Why on the conversation
 *
 * It is a fact about the upload, given once by whatever created it, exactly like `order_id` beside it.
 * Putting it on the job would store the same value twice for a paired upload and give the two copies
 * somewhere to disagree.
 *
 * ## Why not UNIQUE
 *
 * Three channels of one call deliberately share the value, and a recording re-uploaded after a failed
 * transcription legitimately repeats it. Uniqueness here would refuse both. What reads this instead
 * asks whether a call has **exactly one confirmed mixed recording** and declines to answer when it does
 * not — a question about the data, asked at read time, rather than a constraint that would have to be
 * relaxed the first time somebody uploaded a file twice.
 *
 * ## Why the index is on the pair
 *
 * The lookup is always "the other recordings of this call, at this store". Store leads because that is
 * how every other query on this table is scoped, and because the provider's own uniqueness for a
 * session id across stores is unverified — `order58_call_imports` includes the store in its key for the
 * same reason, and this mirrors it rather than assuming more than that table does.
 *
 * ## Why nullable, and nothing back-filled
 *
 * Every row that exists today was uploaded by hand and has no session id to record. NULL is the honest
 * value for those and means "this recording is not known to belong to a call", which the reader treats
 * as "show this recording on its own" — exactly the behaviour those rows have now. Adding the column
 * nullable rewrites no row and locks no upload.
 *
 * Existing rows can be linked afterwards by `kf:audio:link-call-sessions`, which is opt-in, reads the
 * id back out of the provider's own filename convention, and is not part of this migration: a backfill
 * that changes what screens show does not belong in a schema change nobody was watching.
 */
final class M260929140000AddConversationCallSession implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';

    /** The provider's session ids are eight digits; this matches `order58_call_imports.call_session_id`. */
    private const LENGTH = 32;

    private const INDEX = 'ix_audio_conversations_call';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD COLUMN `call_session_id` VARCHAR(' . self::LENGTH . ') NULL AFTER `order_id`,
                ADD INDEX `' . self::INDEX . '` (`store_source_id`, `call_session_id`)',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A session id links recordings that nothing else links. Dropping the column would break those
     * relationships with no way to rebuild them for anything the filename convention does not cover,
     * so this stops and says how much is at stake. A database where nothing has been linked yet
     * reverts cleanly.
     */
    public function down(MigrationBuilder $b): void
    {
        $linked = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::CONVERSATIONS . '` WHERE `call_session_id` IS NOT NULL',
        )->queryScalar();

        if ($linked > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $linked . ' upload(s) are linked to a call session, and this '
                . 'column is the only record of which recordings belong to the same call. Export it, or '
                . 'clear those values first.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                DROP INDEX `' . self::INDEX . '`,
                DROP COLUMN `call_session_id`',
        );
    }
}
