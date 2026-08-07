<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\DailySyncScheduleRepositoryInterface;
use DateTimeImmutable;

use function count;

/**
 * In-memory daily-sync reservations for the scheduler unit test. Enforces the UNIQUE(sync_type, ny_date) guard:
 * {@see reserve()} returns null when a row already exists for that key.
 */
final class InMemoryDailySyncScheduleRepository implements DailySyncScheduleRepositoryInterface
{
    /** @var array<int, array{id: int, sync_type: string, ny_date: string, status: string, integration_sync_run_id: int|null, attempts: int}> */
    private array $rows = [];

    private int $nextId = 0;

    public function find(string $syncType, string $nyDate): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['sync_type'] === $syncType && $row['ny_date'] === $nyDate) {
                return ['id' => $row['id'], 'status' => $row['status'], 'integration_sync_run_id' => $row['integration_sync_run_id']];
            }
        }

        return null;
    }

    public function reserve(string $syncType, string $nyDate, DateTimeImmutable $now): ?int
    {
        if ($this->find($syncType, $nyDate) !== null) {
            return null; // UNIQUE(sync_type, ny_date) violated.
        }
        $id = ++$this->nextId;
        $this->rows[$id] = [
            'id' => $id,
            'sync_type' => $syncType,
            'ny_date' => $nyDate,
            'status' => 'pending',
            'integration_sync_run_id' => null,
            'attempts' => 0,
        ];

        return $id;
    }

    public function markEnqueued(int $id, ?int $runId, DateTimeImmutable $now): void
    {
        $this->rows[$id]['status'] = 'enqueued';
        $this->rows[$id]['integration_sync_run_id'] = $runId;
    }

    public function markFailed(int $id, string $error, DateTimeImmutable $now): void
    {
        $this->rows[$id]['status'] = 'failed';
        ++$this->rows[$id]['attempts'];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
