<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Records which upload card a conversation came from: the mixed, the caller or the callee recording.
 *
 * ## Why a column rather than something derived
 *
 * The store page offers three upload cards, and all three post the same thing — one recording, mode
 * `COMMON`, source role `COMMON`. That is correct for the pipeline (the speakers are discovered either
 * way) but it means the choice the administrator made was kept nowhere at all, so the store's history
 * could only ever print "Common / Mixed" for every row. There is nothing to derive it from after the
 * fact: the filename belongs to the provider that generated it, not to us, and reading `-caller` out of
 * it would be guessing from a string an operator can rename.
 *
 * ## Why it sits on the conversation
 *
 * It describes the upload, not the recording's contents. `source_role` on the job already names what a
 * file holds and is read by the worker; adding caller/callee to *that* would put a display label into
 * the diarization path, where `COMMON` is what makes speakers get worked out. This column is read by
 * templates and by nothing else.
 *
 * ## Why nullable, and nothing back-filled
 *
 * Every conversation uploaded before this existed came through a card nobody recorded, and writing
 * `MIXED` across all of them would invent a fact — some were caller or callee uploads. NULL means "not
 * recorded", the history falls back to the conversation's mode, and no existing row is rewritten.
 *
 * A separate Customer + Agent upload stays NULL too: its mode already describes it, and these three
 * values do not.
 *
 * ## Raw SQL
 *
 * A CHECK constraint, which the fluent builder cannot express — the same reason
 * `M260915100000AddTranscriptionProvider` gives.
 */
final class M260921100000AddConversationRecordingType implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';

    public function up(MigrationBuilder $b): void
    {
        // Nullable, so adding it rewrites no existing row.
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD COLUMN `recording_type` VARCHAR(16) NULL AFTER `mode`,
                ADD CONSTRAINT `chk_audio_conversations_recording_type`
                    CHECK (`recording_type` IS NULL
                           OR `recording_type` IN (\'MIXED\', \'CALLER\', \'CALLEE\'))',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A caller-only or callee-only upload is recorded nowhere else — every one of them is a `COMMON`
     * conversation with a `COMMON` child, exactly like a mixed one. Dropping the column would make
     * those uploads read as mixed recordings, which is a quiet falsification rather than a visible
     * loss, so this stops and says how many rows are at stake.
     *
     * A database where only mixed recordings were uploaded reverts cleanly: nothing distinguishing is
     * lost.
     */
    public function down(MigrationBuilder $b): void
    {
        $sided = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::CONVERSATIONS . '`
             WHERE `recording_type` IN (\'CALLER\', \'CALLEE\')',
        )->queryScalar();

        if ($sided > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $sided . ' conversation(s) were uploaded as a caller or '
                . 'callee recording, and this column is the only record of that. Dropping it would '
                . 'make them read as mixed recordings. Export the column, or remove those uploads '
                . 'first.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                DROP CHECK `chk_audio_conversations_recording_type`',
        );
        $b->execute('ALTER TABLE `' . self::CONVERSATIONS . '` DROP COLUMN `recording_type`');
    }
}
