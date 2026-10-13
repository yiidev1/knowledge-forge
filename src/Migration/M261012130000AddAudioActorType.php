<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

use function sprintf;

/**
 * Lets an Order58 operation user be recorded as the actor on audio work, without pretending to be an
 * administrator.
 *
 * ## The problem this solves
 *
 * Operation users have the same permissions as an administrator inside the Order Testing workflow, and
 * that workflow writes audio: uploads, replacements, transcript corrections, TTS requests. Every one of
 * those recorded its actor in a column foreign-keyed to `admin_users`, two of them `NOT NULL`. An
 * operation user has no `admin_users` row, so the write had nowhere to go — and writing their Order58
 * `admin_id` into an `admin_users` column would silently attribute the work to whichever administrator
 * happened to share that number. That is the misattribution the whole pair-of-columns design exists to
 * prevent, and it cannot be undone after the fact.
 *
 * ## No foreign key is dropped, and no existing row changes meaning
 *
 * Each actor keeps its own column:
 *
 * - `*_admin_id` — unchanged, still foreign-keyed to `admin_users`, still the only thing an
 *   administrator writes;
 * - `*_operation_id` — new, holding Order58's own `admin_id`;
 * - `*_by_type` — new, saying which of the two is populated.
 *
 * Existing rows become explicitly `'admin'` with the id they already had. Their attribution, their
 * foreign key and their join all survive untouched — the only difference is that the realm is now
 * stated rather than assumed.
 *
 * The two `NOT NULL`s on `uploaded_by_admin_id` must relax, because an operation upload genuinely has no
 * administrator id. The pair CHECK added alongside restores the guarantee exactly: every row still names
 * exactly one actor, and a row naming none, or both, is refused.
 *
 * ## The vocabulary is lower case here
 *
 * `audio_segment_revisions.edited_by_type` has stored `'admin'`/`'agent'` since review shipped.
 * `order_testing_attempts.initiated_by_type` spells its realms in UPPER case. Both predate this
 * migration; each is correct in its own table, and unifying them would mean rewriting rows this feature
 * has no business touching. So the audio tables keep lower case.
 *
 * ## Why the operation id has no foreign key of its own
 *
 * `order58_agents.admin_id` is UNIQUE and the mirror is upsert-only, so one *would* work. The module's
 * existing polymorphic actors — `audio_segment_revisions`, `order_testing_attempts` — deliberately have
 * none, because the mirror is a cache of someone else's table and a RESTRICT would let a future prune
 * block audio writes. The `> 0` CHECK and the pair CHECK carry the integrity instead.
 */
final class M261012130000AddAudioActorType implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = '{{%audio_conversations}}';
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const RENDITIONS = '{{%audio_tts_renditions}}';
    private const REVISIONS = '{{%audio_segment_revisions}}';

    public function up(MigrationBuilder $b): void
    {
        $this->widenRevisionActors($b);
        $this->addConversationActor($b);
        $this->addJobActors($b);
        $this->addRenditionActor($b);
    }

    /**
     * Transcript corrections need no new columns at all.
     *
     * This table was built polymorphic — `edited_by_type` beside `edited_by_id`, no foreign key — so the
     * whole change is one more admitted value. Every move, split, merge, text edit, confirm and revert an
     * operation user performs is recorded correctly the moment this lands.
     */
    private function widenRevisionActors(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE ' . self::REVISIONS . ' DROP CHECK `chk_audio_segment_revisions_by_type`');
        $b->execute(
            'ALTER TABLE ' . self::REVISIONS . ' ADD CONSTRAINT `chk_audio_segment_revisions_by_type` '
            . 'CHECK (`edited_by_type` IN (\'admin\',\'agent\',\'operation\'))',
        );
    }

    private function addConversationActor(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::CONVERSATIONS
            . ' ADD COLUMN `uploaded_by_type` VARCHAR(16) NOT NULL DEFAULT \'admin\' AFTER `uploaded_by_admin_id`,'
            . ' ADD COLUMN `uploaded_by_operation_id` BIGINT UNSIGNED NULL AFTER `uploaded_by_type`',
        );

        // The foreign key to `admin_users` is untouched by MODIFY; only nullability changes.
        $b->execute(
            'ALTER TABLE ' . self::CONVERSATIONS . ' MODIFY COLUMN `uploaded_by_admin_id` BIGINT NULL',
        );

        $b->execute(
            'ALTER TABLE ' . self::CONVERSATIONS
            . ' ADD CONSTRAINT `chk_audio_conversations_uploader` CHECK ('
            . '(`uploaded_by_type` = \'admin\' AND `uploaded_by_admin_id` IS NOT NULL'
            . ' AND `uploaded_by_operation_id` IS NULL)'
            . ' OR (`uploaded_by_type` = \'operation\' AND `uploaded_by_operation_id` IS NOT NULL'
            . ' AND `uploaded_by_admin_id` IS NULL))',
        );
    }

    private function addJobActors(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::JOBS
            . ' ADD COLUMN `uploaded_by_type` VARCHAR(16) NOT NULL DEFAULT \'admin\' AFTER `uploaded_by_admin_id`,'
            . ' ADD COLUMN `uploaded_by_operation_id` BIGINT UNSIGNED NULL AFTER `uploaded_by_type`,'
            . ' ADD COLUMN `reviewed_by_type` VARCHAR(16) NULL AFTER `reviewed_by_admin_id`,'
            . ' ADD COLUMN `reviewed_by_operation_id` BIGINT UNSIGNED NULL AFTER `reviewed_by_type`',
        );

        $b->execute('ALTER TABLE ' . self::JOBS . ' MODIFY COLUMN `uploaded_by_admin_id` BIGINT NULL');

        // Reviewed rows predate the type column, so state their realm before the CHECK is asked to
        // enforce it. An unreviewed job keeps all three review columns NULL, which the CHECK admits.
        $b->execute(
            'UPDATE ' . self::JOBS . ' SET `reviewed_by_type` = \'admin\' '
            . 'WHERE `reviewed_by_admin_id` IS NOT NULL AND `reviewed_by_type` IS NULL',
        );

        $b->execute(
            'ALTER TABLE ' . self::JOBS
            . ' ADD CONSTRAINT `chk_audio_jobs_uploader` CHECK ('
            . '(`uploaded_by_type` = \'admin\' AND `uploaded_by_admin_id` IS NOT NULL'
            . ' AND `uploaded_by_operation_id` IS NULL)'
            . ' OR (`uploaded_by_type` = \'operation\' AND `uploaded_by_operation_id` IS NOT NULL'
            . ' AND `uploaded_by_admin_id` IS NULL))',
        );

        $b->execute(
            'ALTER TABLE ' . self::JOBS
            . ' ADD CONSTRAINT `chk_audio_jobs_reviewer` CHECK ('
            . '(`reviewed_by_type` IS NULL AND `reviewed_by_admin_id` IS NULL'
            . ' AND `reviewed_by_operation_id` IS NULL)'
            . ' OR (`reviewed_by_type` = \'admin\' AND `reviewed_by_admin_id` IS NOT NULL'
            . ' AND `reviewed_by_operation_id` IS NULL)'
            . ' OR (`reviewed_by_type` = \'operation\' AND `reviewed_by_operation_id` IS NOT NULL'
            . ' AND `reviewed_by_admin_id` IS NULL))',
        );
    }

    private function addRenditionActor(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE ' . self::RENDITIONS
            . ' ADD COLUMN `requested_by_type` VARCHAR(16) NULL AFTER `requested_by_admin_id`,'
            . ' ADD COLUMN `requested_by_operation_id` BIGINT UNSIGNED NULL AFTER `requested_by_type`',
        );

        $b->execute(
            'UPDATE ' . self::RENDITIONS . ' SET `requested_by_type` = \'admin\' '
            . 'WHERE `requested_by_admin_id` IS NOT NULL AND `requested_by_type` IS NULL',
        );

        $b->execute(
            'ALTER TABLE ' . self::RENDITIONS
            . ' ADD CONSTRAINT `chk_audio_renditions_requester` CHECK ('
            . '(`requested_by_type` IS NULL AND `requested_by_admin_id` IS NULL'
            . ' AND `requested_by_operation_id` IS NULL)'
            . ' OR (`requested_by_type` = \'admin\' AND `requested_by_admin_id` IS NOT NULL'
            . ' AND `requested_by_operation_id` IS NULL)'
            . ' OR (`requested_by_type` = \'operation\' AND `requested_by_operation_id` IS NOT NULL'
            . ' AND `requested_by_admin_id` IS NULL))',
        );
    }

    /**
     * Refuses while any operation-attributed audio work exists.
     *
     * Reverting would drop the only columns that say who did that work, and restoring `NOT NULL` on
     * `uploaded_by_admin_id` would fail against operation uploads anyway — or, if those rows had been
     * removed first, would mean recordings and their transcripts were deleted to make a rollback fit.
     * Neither is a decision a migration should take, so it stops and names the count.
     */
    public function down(MigrationBuilder $b): void
    {
        $this->refuseIfAttributed($b, self::CONVERSATIONS, 'uploaded_by_type', 'audio upload(s)');
        $this->refuseIfAttributed($b, self::JOBS, 'uploaded_by_type', 'transcription job upload(s)');
        $this->refuseIfAttributed($b, self::JOBS, 'reviewed_by_type', 'transcript review(s)');
        $this->refuseIfAttributed($b, self::RENDITIONS, 'requested_by_type', 'TTS request(s)');
        $this->refuseIfAttributed($b, self::REVISIONS, 'edited_by_type', 'transcript correction(s)');

        $b->execute('ALTER TABLE ' . self::RENDITIONS . ' DROP CHECK `chk_audio_renditions_requester`');
        $b->execute(
            'ALTER TABLE ' . self::RENDITIONS
            . ' DROP COLUMN `requested_by_type`, DROP COLUMN `requested_by_operation_id`',
        );

        $b->execute('ALTER TABLE ' . self::JOBS . ' DROP CHECK `chk_audio_jobs_reviewer`');
        $b->execute('ALTER TABLE ' . self::JOBS . ' DROP CHECK `chk_audio_jobs_uploader`');
        $b->execute('ALTER TABLE ' . self::JOBS . ' MODIFY COLUMN `uploaded_by_admin_id` BIGINT NOT NULL');
        $b->execute(
            'ALTER TABLE ' . self::JOBS
            . ' DROP COLUMN `uploaded_by_type`, DROP COLUMN `uploaded_by_operation_id`,'
            . ' DROP COLUMN `reviewed_by_type`, DROP COLUMN `reviewed_by_operation_id`',
        );

        $b->execute('ALTER TABLE ' . self::CONVERSATIONS . ' DROP CHECK `chk_audio_conversations_uploader`');
        $b->execute(
            'ALTER TABLE ' . self::CONVERSATIONS . ' MODIFY COLUMN `uploaded_by_admin_id` BIGINT NOT NULL',
        );
        $b->execute(
            'ALTER TABLE ' . self::CONVERSATIONS
            . ' DROP COLUMN `uploaded_by_type`, DROP COLUMN `uploaded_by_operation_id`',
        );

        $b->execute('ALTER TABLE ' . self::REVISIONS . ' DROP CHECK `chk_audio_segment_revisions_by_type`');
        $b->execute(
            'ALTER TABLE ' . self::REVISIONS . ' ADD CONSTRAINT `chk_audio_segment_revisions_by_type` '
            . 'CHECK (`edited_by_type` IN (\'admin\',\'agent\'))',
        );
    }

    private function refuseIfAttributed(MigrationBuilder $b, string $table, string $column, string $what): void
    {
        $count = (int) $b->getDb()
            ->createCommand(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE `' . $column . '` = \'operation\'',
            )
            ->queryScalar();

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                '%d %s are attributed to the operation realm. Reverting would drop the columns that '
                . 'record who performed them. Re-attribute or remove those rows deliberately first.',
                $count,
                $what,
            ));
        }
    }
}
