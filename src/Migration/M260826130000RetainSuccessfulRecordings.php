<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Keep successful conversations, and their recordings, indefinitely by default.
 *
 * The original design treated a recording as scaffolding — deleted the moment its transcript existed —
 * and expired the whole job after 24 hours. That was wrong for what this data is actually for: the
 * conversations are the product, and are meant to be read back later.
 *
 * Two changes carry that:
 *
 * * **`retained_audio_path`** records the source recording that was moved into permanent storage after
 *   a successful transcription. It is a bare filename, never a path — the same rule `stored_audio_path`
 *   already follows — so nothing in the database can be used to address a file outside the store.
 *
 * * **`expires_at` becomes nullable**, and NULL means "never expires". Existing rows are backfilled to
 *   NULL so that applying this migration cannot delete a conversation that already exists. Turning
 *   expiry back on is a configuration change (`AUDIO_TRANSCRIPTION_RETENTION_SECONDS`), not a schema
 *   change.
 *
 * The index on `expires_at` is kept: the purge query now filters `expires_at IS NOT NULL`, which the
 * same index still serves.
 */
final class M260826130000RetainSuccessfulRecordings implements RevertibleMigrationInterface
{
    private const TABLE = 'audio_transcription_jobs';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' ADD COLUMN `retained_audio_path` VARCHAR(190) NULL AFTER `stored_audio_path`,'
            . ' MODIFY COLUMN `expires_at` DATETIME NULL',
        );

        // Nothing that exists today should suddenly become expirable because this ran. NULL is the
        // "keep" value, and it matches the new default.
        $b->execute('UPDATE `' . self::TABLE . '` SET `expires_at` = NULL');
    }

    public function down(MigrationBuilder $b): void
    {
        // `expires_at` is NOT NULL again on the way back, so every row needs a value. A far-future date
        // rather than "now + 24h": reverting a migration must not schedule a mass deletion of data the
        // operator has been keeping deliberately.
        $b->execute(
            'UPDATE `' . self::TABLE . '` SET `expires_at` = \'2099-12-31 00:00:00\''
            . ' WHERE `expires_at` IS NULL',
        );

        $b->execute(
            'ALTER TABLE `' . self::TABLE . '`'
            . ' DROP COLUMN `retained_audio_path`,'
            . ' MODIFY COLUMN `expires_at` DATETIME NOT NULL',
        );
    }
}
