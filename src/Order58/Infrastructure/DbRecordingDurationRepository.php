<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\CallRecordings\RecordingDurationBacklog;
use App\Order58\Domain\CallRecordings\RecordingDurationRepositoryInterface;
use App\Order58\Domain\CallRecordings\RecordingToMeasure;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function sprintf;

use const SORT_ASC;

/**
 * `order58_call_recordings.duration_*`, the measured length of each recording and the retry state of
 * measuring it.
 *
 * Every write is conditional on the row still being unmeasured, so a duration, once stored, is never
 * overwritten — by a second run, by a page visit racing the background job, or by a retry.
 */
final readonly class DbRecordingDurationRepository implements RecordingDurationRepositoryInterface
{
    private const RECORDINGS = '{{%order58_call_recordings}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function due(int $limit, int $maxAttempts, DateTimeImmutable $now): array
    {
        // Served by ix_o58_call_recordings_duration_queue (duration_seconds, duration_next_attempt_at, id).
        $rows = (new Query($this->connection))
            ->select(['id', 'url', 'duration_attempts'])
            ->from(self::RECORDINGS)
            ->where(['duration_seconds' => null, 'delisted_at' => null])
            ->andWhere(['<', 'duration_attempts', $maxAttempts])
            ->andWhere(['or', ['duration_next_attempt_at' => null], ['<=', 'duration_next_attempt_at', self::ts($now)]])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = new RecordingToMeasure((int) $row['id'], (string) $row['url'], (int) $row['duration_attempts']);
            }
        }

        return $out;
    }

    public function claim(int $recordingId, DateTimeImmutable $now, DateTimeImmutable $leaseUntil): bool
    {
        return $this->connection->createCommand()->update(
            self::RECORDINGS,
            ['duration_next_attempt_at' => self::ts($leaseUntil)],
            [
                'and',
                ['id' => $recordingId, 'duration_seconds' => null],
                ['or', ['duration_next_attempt_at' => null], ['<=', 'duration_next_attempt_at', self::ts($now)]],
            ],
        )->execute() === 1;
    }

    public function record(int $recordingId, float $seconds, DateTimeImmutable $now): bool
    {
        return $this->connection->createCommand()->update(
            self::RECORDINGS,
            [
                // Seven decimals: the length is a whole number of 1/16000 s steps, which this holds exactly.
                'duration_seconds' => sprintf('%.7F', $seconds),
                'duration_measured_at' => self::ts($now),
                'duration_next_attempt_at' => null,
                'duration_failure' => null,
            ],
            ['id' => $recordingId, 'duration_seconds' => null],
        )->execute() === 1;
    }

    public function failed(int $recordingId, string $failure, DateTimeImmutable $retryAt, bool $countsAsAttempt): void
    {
        $columns = [
            'duration_failure' => $failure,
            'duration_next_attempt_at' => self::ts($retryAt),
        ];

        if ($countsAsAttempt) {
            $columns['duration_attempts'] = new Expression('LEAST(duration_attempts + 1, 65535)');
        }

        $this->connection->createCommand()->update(
            self::RECORDINGS,
            $columns,
            ['id' => $recordingId, 'duration_seconds' => null],
        )->execute();
    }

    public function abandon(int $recordingId, string $failure, int $maxAttempts): void
    {
        $this->connection->createCommand()->update(
            self::RECORDINGS,
            ['duration_failure' => $failure, 'duration_attempts' => $maxAttempts, 'duration_next_attempt_at' => null],
            ['id' => $recordingId, 'duration_seconds' => null],
        )->execute();
    }

    public function unmeasured(int $maxAttempts): RecordingDurationBacklog
    {
        $row = (new Query($this->connection))
            ->select([
                'measured' => new Expression('SUM(duration_seconds IS NOT NULL)'),
                'pending' => new Expression('SUM(duration_seconds IS NULL AND duration_attempts < :max)', [':max' => $maxAttempts]),
                'exhausted' => new Expression('SUM(duration_seconds IS NULL AND duration_attempts >= :max2)', [':max2' => $maxAttempts]),
            ])
            ->from(self::RECORDINGS)
            ->where(['delisted_at' => null])
            ->one();

        return new RecordingDurationBacklog(
            is_array($row) ? (int) $row['measured'] : 0,
            is_array($row) ? (int) $row['pending'] : 0,
            is_array($row) ? (int) $row['exhausted'] : 0,
        );
    }

    public function resetExhausted(int $maxAttempts): int
    {
        return $this->connection->createCommand()->update(
            self::RECORDINGS,
            ['duration_attempts' => 0, 'duration_next_attempt_at' => null],
            ['and', ['duration_seconds' => null], ['>=', 'duration_attempts', $maxAttempts]],
        )->execute();
    }

    /** Stored as UTC, like every other timestamp in this database. */
    private static function ts(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
