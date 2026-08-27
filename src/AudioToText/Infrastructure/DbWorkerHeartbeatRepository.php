<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\WorkerHeartbeat;
use App\AudioToText\Domain\WorkerHeartbeatRepositoryInterface;
use App\AudioToText\Domain\WorkerMode;
use App\AudioToText\Domain\WorkerProcessState;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_object;

/**
 * The single-row worker heartbeat.
 *
 * Written as an `INSERT ... ON DUPLICATE KEY UPDATE` against a fixed primary key of 1, so there is
 * exactly one row for the lifetime of the installation and no cleanup to schedule. A `CHECK (id = 1)`
 * in the migration makes that structural rather than merely conventional.
 *
 * A table rather than a file in `runtime/`: the web tier can read it without being given access to the
 * worker's private directory, it survives the orphan sweep, and it can be asserted on in an integration
 * test through the existing `IntegrationDb` helper.
 */
final readonly class DbWorkerHeartbeatRepository implements WorkerHeartbeatRepositoryInterface
{
    private const TABLE = '{{%audio_worker_heartbeat}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
    ) {}

    public function read(): ?WorkerHeartbeat
    {
        $row = (new Query($this->connection))->from(self::TABLE)->where(['id' => 1])->limit(1)->one();

        if (!is_array($row) && !is_object($row)) {
            return null;
        }

        $row = (array) $row;
        if (($row['beat_at'] ?? null) === null) {
            return null;
        }

        return new WorkerHeartbeat(
            DbDateTime::parse((string) ($row['started_at'] ?? $row['beat_at'])),
            DbDateTime::parse((string) $row['beat_at']),
            WorkerProcessState::fromStorage($row['state'] === null ? null : (string) $row['state']),
            DbDateTime::parse((string) ($row['last_tick_at'] ?? $row['beat_at'])),
            WorkerMode::fromStorage($row['mode'] === null ? null : (string) $row['mode']),
            (string) ($row['last_tick_outcome'] ?? ''),
            $row['current_job_id'] === null ? null : (int) $row['current_job_id'],
        );
    }

    /**
     * `$tick` is what separates the two facts this table carries.
     *
     * A tick stamps `last_tick_at` and happens once per invocation — including for a `--once` run that
     * finds an empty queue and exits in milliseconds. That case is precisely the one that proves a
     * schedule is alive, and it is invisible to any amount of process-liveness checking.
     *
     * Everything else refreshes only `beat_at`, which says a process exists right now.
     */
    public function beat(
        WorkerProcessState $state,
        WorkerMode $mode,
        bool $tick,
        ?string $tickOutcome = null,
        ?int $currentJobId = null,
    ): void {
        $now = DbDateTime::format($this->clock->now());

        $sql = <<<SQL
            INSERT INTO {{%audio_worker_heartbeat}}
                (`id`, `started_at`, `beat_at`, `state`, `last_tick_at`, `mode`, `last_tick_outcome`, `current_job_id`)
            VALUES
                (1, :now, :now, :state, :now, :mode, :outcome, :jobId)
            ON DUPLICATE KEY UPDATE
                `beat_at` = :now,
                `state` = :state,
                `mode` = :mode,
                `current_job_id` = :jobId,
                `last_tick_at` = IF(:tick, :now, `last_tick_at`),
                `last_tick_outcome` = IF(:tick, :outcome, `last_tick_outcome`)
            SQL;

        $this->connection->createCommand($sql, [
            ':now' => $now,
            ':state' => $state->value,
            ':mode' => $mode->value,
            ':outcome' => $tickOutcome ?? '',
            ':jobId' => $currentJobId,
            ':tick' => $tick ? 1 : 0,
        ])->execute();
    }
}
