<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Reviewing a conversation and confirming who was speaking are two different acts.
 *
 * Without this column they would be one. `reviewed_segments` existing would be the only signal, so
 * fixing a single turn boundary on a call the machine refused to commit to would silently promote every
 * *untouched* guessed role in that thread to a human-confirmed one. An administrator who corrected one
 * word would have unknowingly asserted the identity of both speakers for the whole call.
 *
 * So confirmation is stated explicitly, and only ever by a person.
 *
 * ## Why a nullable timestamp rather than a boolean
 *
 * It carries *when* at the same cost, and it is the idiom this codebase already uses for exactly this
 * shape of state: `messages.superseded_at`, `chat_answer_scores.dismissed_at`, `reviewed_at`. NULL and
 * non-NULL are the only two states, and the non-NULL one is self-documenting.
 *
 * ## Why there is no `roles_confirmed_by_admin_id`
 *
 * Confirming is recorded as a `CONFIRM_ROLES` revision, so who did it and when are already in the audit
 * trail. A second column would be a duplicate that could disagree with it.
 *
 * ## The rule this enables
 *
 *     rolesConfirmed = (the machine's own gate already published this split)
 *                   OR roles_confirmed_at IS NOT NULL
 *
 * A call the pipeline already published needs no extra step — it cleared its gates on its own. A
 * NEEDS_REVIEW call keeps showing Speaker 1 / Speaker 2 through any number of structural corrections,
 * until somebody says otherwise.
 *
 * `ConversationView` needs no change to enforce this: `reviewed_agent_text` and
 * `reviewed_customer_text` are derived only once roles are confirmed, and that view already refuses to
 * print role labels for a job with no aggregate text.
 */
final class M260831160000AddRolesConfirmation implements RevertibleMigrationInterface
{
    private const JOBS = 'audio_transcription_jobs';
    private const REVISIONS = 'audio_segment_revisions';
    private const OPERATION_CHECK = 'chk_audio_segment_revisions_operation';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::JOBS . '`'
            . ' ADD COLUMN `roles_confirmed_at` DATETIME NULL AFTER `reviewed_by_admin_id`',
        );

        // MySQL cannot widen a CHECK in place, so the constraint is replaced. Both statements are in one
        // ALTER so the table is never briefly without the constraint.
        $b->execute(
            'ALTER TABLE `' . self::REVISIONS . '`'
            . ' DROP CHECK `' . self::OPERATION_CHECK . '`,'
            . ' ADD CONSTRAINT `' . self::OPERATION_CHECK . '`'
            . "   CHECK (`operation` IN ('MOVE','SPLIT','MERGE','EDIT_TEXT','REVERT','CONFIRM_ROLES'))",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        // Narrowing the constraint again would fail on any row already using the new value, and the
        // failure would arrive mid-ALTER with the constraint dropped. Refuse first, with a count, so the
        // operator knows what is in the way.
        $confirmations = (int) $b->getDb()
            ->createCommand(
                'SELECT COUNT(*) FROM `' . self::REVISIONS . "` WHERE `operation` = 'CONFIRM_ROLES'",
            )
            ->queryScalar();

        if ($confirmations > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . self::REVISIONS . ' holds ' . $confirmations
                . ' CONFIRM_ROLES revision(s), which the narrower constraint would reject. '
                . 'Export the correction history first.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::REVISIONS . '`'
            . ' DROP CHECK `' . self::OPERATION_CHECK . '`,'
            . ' ADD CONSTRAINT `' . self::OPERATION_CHECK . '`'
            . "   CHECK (`operation` IN ('MOVE','SPLIT','MERGE','EDIT_TEXT','REVERT'))",
        );

        $b->execute('ALTER TABLE `' . self::JOBS . '` DROP COLUMN `roles_confirmed_at`');
    }
}
