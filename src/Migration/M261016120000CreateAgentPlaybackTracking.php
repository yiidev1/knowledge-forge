<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * What agents listened to, when, and with which pause between messages.
 *
 * ## Two tables, because two things are being recorded
 *
 * A **session** is one attempt to hear a recording read aloud: one row per press of Play, carrying the
 * gap that attempt used and how far it got. The summary counts rows here, so a count is one indexed
 * scan rather than a fold over an event log.
 *
 * An **event** is a thing that happened — requested, started, paused, resumed, stopped, finished,
 * failed. Append-only. It keeps the sequence a session row cannot, because each status overwrites the
 * last one.
 *
 * ## Why `session_id` is nullable, which is the unusual part
 *
 * Changing the dropdown is an event with no attempt behind it. Somebody may pick 0.30, think better of
 * it, pick 0.50, and never press Play — and that is activity worth keeping, but it is emphatically not
 * a play. The alternatives were both worse: a session row for a selection would be counted as an
 * attempt by every query that counts attempts, and a third table would be a third place to keep one
 * agent's identity correct. So a selection is an event that belongs to nobody, `session_id` is NULL,
 * and the CHECK below makes the two kinds impossible to confuse in either direction.
 *
 * ## No foreign key to the recording, deliberately
 *
 * `job_public_id` names a row in `audio_transcription_jobs` and is not a foreign key to it. Two
 * reasons, both structural: this application's module isolation test refuses any migration that
 * touches an `audio_*` table alongside anything else, and every cross-module reference here already
 * works this way — `order_testing_*` names `order58_orders` by value, as the mirror tables do. The
 * relationship is proved before the write instead, by the same scope check that admits the agent to
 * the recording at all.
 *
 * ## Identity
 *
 * `agent_admin_id` is Order58's own `admin_id` — the identity the agent session carries and the one
 * `agent_login_activity` already keys on — never the local `order58_agents.id`. The display name is
 * snapshotted beside it so a renamed or removed agent does not rewrite history. The order is the pair
 * `(store_source_id, source_order_id)`, nullable on the order side because a recording may name no
 * mirrored order, and the recording itself is the job's public id: one order can hold three of them,
 * and counting them together would be counting three different things as one.
 *
 * ## Nothing here is scheduled
 *
 * A session whose browser was closed mid-play is left as `PLAYING` with a `started_at` and no finish.
 * That is the truth about it, and the reporting says so rather than a worker inventing an ending at
 * some arbitrary later minute.
 */
final class M261016120000CreateAgentPlaybackTracking implements RevertibleMigrationInterface
{
    private const SESSIONS = 'agent_playback_sessions';
    private const EVENTS = 'agent_playback_events';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE TABLE `' . self::SESSIONS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- The browser\'s token for this attempt. A double click, a retried POST and a reloaded
                -- tab all carry the same one, and the UNIQUE key below is what makes them one row
                -- rather than three counted plays.
                `public_id`             CHAR(32) NOT NULL,

                -- Order58\'s `admin_id`, taken from the authenticated session and never from the body.
                -- The name is a snapshot: agents are mirrored from another system and can be renamed.
                `agent_admin_id`        BIGINT UNSIGNED NOT NULL,
                `agent_display_name`    VARCHAR(255) NOT NULL,

                -- The order, as the pair. NULL on the order side for a recording that names none.
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NULL,

                -- The recording actually played. Not a foreign key; see the class docblock.
                `job_public_id`         CHAR(32) NOT NULL,
                `recording_label`       VARCHAR(16) NULL,

                -- The gap this attempt used, snapshotted when Play was pressed. Changing the dropdown
                -- afterwards records a selection and applies to the next attempt, never to this row.
                `gap_ms`                SMALLINT UNSIGNED NOT NULL,

                `message_total`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `message_played`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,

                `status`                VARCHAR(16) NOT NULL,

                `requested_at`          DATETIME NOT NULL,

                -- Set once, when the speech engine reports the first utterance beginning, and never
                -- cleared. This is what a play is counted by: a session that failed before the first
                -- word has NULL here however it ended, and one that started counts whether it finished,
                -- was stopped or later failed. The status alone cannot express that.
                `started_at`            DATETIME NULL,
                `finished_at`           DATETIME NULL,

                `created_at`            DATETIME NOT NULL,
                `updated_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                UNIQUE KEY `ux_agent_playback_sessions_public` (`public_id`),

                -- The reporting\'s own path: one recording of one order, newest first.
                KEY `ix_agent_playback_sessions_recording`
                    (`store_source_id`, `source_order_id`, `job_public_id`, `started_at`),

                -- And one agent\'s activity over time.
                KEY `ix_agent_playback_sessions_agent` (`agent_admin_id`, `started_at`),

                CONSTRAINT `chk_agent_playback_sessions_status`
                    CHECK (`status` IN (\'REQUESTED\', \'PLAYING\', \'COMPLETED\', \'STOPPED\', \'ERROR\')),

                -- A bound, not the offered list. Whatever values the dropdown shows are enforced in
                -- PHP, where they are declared once; naming them here would mean a schema migration
                -- every time the list changes — and would make the rows that used an earlier list
                -- illegal, which is the opposite of what a record is for.
                CONSTRAINT `chk_agent_playback_sessions_gap`
                    CHECK (`gap_ms` BETWEEN 100 AND 5000),

                CONSTRAINT `chk_agent_playback_sessions_progress`
                    CHECK (`message_played` <= `message_total`),

                -- Finished without ever starting is a REAL state, not a contradiction: an attempt
                -- whose engine refused — no voices installed, a `speak()` that never sounded — ends
                -- without a word having been spoken. It is an attempt and an error, and it is not a
                -- play, which is precisely why `started_at` and the status have to be separate
                -- columns. An earlier draft of this table forbade the combination and the integration
                -- suite caught it immediately.
                --
                -- What IS impossible is playing a message nobody could hear, so that is what is
                -- constrained: progress belongs to an attempt that began.
                CONSTRAINT `chk_agent_playback_sessions_progress_needs_start`
                    CHECK (`message_played` = 0 OR `started_at` IS NOT NULL)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $b->execute(
            'CREATE TABLE `' . self::EVENTS . '` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                -- NULL for a dropdown change, which belongs to no attempt. Everything else hangs off
                -- the session it describes. See the CHECK below, which binds the two.
                `session_id`            BIGINT UNSIGNED NULL,

                `event_type`            VARCHAR(24) NOT NULL,

                -- Repeated on the event so a selection can be found without a session to join to, and
                -- so an event keeps the identity it was recorded under even if a later write changes
                -- the session\'s own columns.
                `agent_admin_id`        BIGINT UNSIGNED NOT NULL,
                `agent_display_name`    VARCHAR(255) NOT NULL,
                `store_source_id`       BIGINT UNSIGNED NOT NULL,
                `source_order_id`       BIGINT UNSIGNED NULL,
                `job_public_id`         CHAR(32) NOT NULL,
                `recording_label`       VARCHAR(16) NULL,

                -- The gap this event is about: the one selected, or the one the attempt is using.
                `gap_ms`                SMALLINT UNSIGNED NULL,
                `message_index`         SMALLINT UNSIGNED NULL,
                `detail`                VARCHAR(190) NULL,

                `created_at`            DATETIME NOT NULL,

                PRIMARY KEY (`id`),

                KEY `ix_agent_playback_events_session` (`session_id`, `id`),

                -- Standalone selections, per recording and per agent, newest first. The leading column
                -- is the event type because that is what this index is for: finding the events that
                -- have no session.
                KEY `ix_agent_playback_events_selection`
                    (`event_type`, `store_source_id`, `source_order_id`, `job_public_id`, `id`),

                KEY `ix_agent_playback_events_agent` (`agent_admin_id`, `id`),

                CONSTRAINT `fk_agent_playback_events_session`
                    FOREIGN KEY (`session_id`) REFERENCES `' . self::SESSIONS . '` (`id`)
                    ON DELETE CASCADE ON UPDATE RESTRICT,

                CONSTRAINT `chk_agent_playback_events_type`
                    CHECK (`event_type` IN (
                        \'GAP_SELECTED\', \'PLAY_REQUESTED\', \'PLAY_STARTED\', \'PAUSED\',
                        \'RESUMED\', \'STOPPED\', \'COMPLETED\', \'PLAYBACK_ERROR\'
                    )),

                CONSTRAINT `chk_agent_playback_events_gap`
                    CHECK (`gap_ms` IS NULL OR `gap_ms` BETWEEN 100 AND 5000),

                -- The line between the two kinds of event, drawn by the database rather than trusted
                -- to every query. A selection has no session and must carry the gap it selected;
                -- everything else has a session. Neither can be written as the other.
                CONSTRAINT `chk_agent_playback_events_session_shape`
                    CHECK (
                        (`event_type` = \'GAP_SELECTED\'
                            AND `session_id` IS NULL
                            AND `gap_ms` IS NOT NULL)
                        OR (`event_type` <> \'GAP_SELECTED\'
                            AND `session_id` IS NOT NULL)
                    )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        foreach ([self::EVENTS, self::SESSIONS] as $table) {
            $rows = (int) $b->getDb()->createCommand('SELECT COUNT(*) FROM `' . $table . '`')->queryScalar();

            if ($rows > 0) {
                throw new RuntimeException(
                    $table . ' holds ' . $rows . ' row(s). These record what agents actually listened '
                    . 'to and exist nowhere else — no other table can reconstruct them. Empty it '
                    . 'deliberately if it really must go.',
                );
            }
        }

        $b->execute('DROP TABLE `' . self::EVENTS . '`');
        $b->execute('DROP TABLE `' . self::SESSIONS . '`');
    }
}
