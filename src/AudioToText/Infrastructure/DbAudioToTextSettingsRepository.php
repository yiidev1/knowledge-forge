<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_object;

/**
 * The single-row Audio-to-Text settings table.
 *
 * `INSERT ... ON DUPLICATE KEY UPDATE` against a fixed primary key of 1, exactly as
 * {@see DbWorkerHeartbeatRepository} does — one row for the lifetime of the installation, a
 * `CHECK (id = 1)` in the migration making that structural, and no cleanup to schedule.
 *
 * The migration seeds the row, so the upsert is really an update. The insert half is kept anyway: a
 * database restored from a dump taken before the seed would otherwise silently never save.
 */
final readonly class DbAudioToTextSettingsRepository implements AudioToTextSettingsRepositoryInterface
{
    private const TABLE = '{{%audio_to_text_settings}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    /**
     * Whisper whenever the stored value cannot be trusted.
     *
     * Three cases collapse to the same answer — no row, a NULL, or a string no longer in the enum (a
     * provider removed in a later release). Falling back to the local engine is the safe direction:
     * it is installed, it costs nothing per minute, and a job queued for it cannot surprise anyone
     * with a bill. Defaulting the other way would let a dropped row start spending money.
     */
    public function defaultProvider(): TranscriptionProvider
    {
        $row = (new Query($this->connection))
            ->select(['default_transcription_provider'])
            ->from(self::TABLE)
            ->where(['id' => 1])
            ->limit(1)
            ->one();

        if (!is_array($row) && !is_object($row)) {
            return TranscriptionProvider::Whisper;
        }

        $row = (array) $row;
        $stored = $row['default_transcription_provider'] ?? null;

        return TranscriptionProvider::fromStorage($stored === null ? null : (string) $stored)
            ?? TranscriptionProvider::Whisper;
    }

    public function saveDefaultProvider(TranscriptionProvider $provider, int $adminUserId): void
    {
        $sql = <<<SQL
            INSERT INTO {{%audio_to_text_settings}}
                (`id`, `default_transcription_provider`, `updated_at`, `updated_by_admin_id`)
            VALUES
                (1, :provider, :now, :adminId)
            ON DUPLICATE KEY UPDATE
                `default_transcription_provider` = :provider,
                `updated_at` = :now,
                `updated_by_admin_id` = :adminId
            SQL;

        $this->connection->createCommand($sql, [
            ':provider' => $provider->value,
            ':now' => DbDateTime::format($this->clock->now()),
            ':adminId' => $adminUserId,
        ])->execute();
    }
}
