<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use App\Ai\OpenAi\Dto\VectorStoreFileCounts;

use function count;
use function is_numeric;

/**
 * Account-wide totals derived from the store inventory.
 *
 * Costs are stored as full-precision floats. Rounding happens in the template, once, at the moment of
 * display — rounding here would bake a presentation decision into the persisted snapshot and make the
 * per-store column stop summing to the total.
 */
final readonly class UsageTotals
{
    /**
     * @param array<string, int> $storeStatusCounts Store count per provider status, e.g. `completed`.
     */
    public function __construct(
        public int $storeCount = 0,
        public array $storeStatusCounts = [],
        public int $totalUsageBytes = 0,
        public VectorStoreFileCounts $fileCounts = new VectorStoreFileCounts(),
        public float $totalGib = 0.0,
        public float $billableGib = 0.0,
        public float $estimatedDailyCost = 0.0,
        public float $estimatedProjectedCost = 0.0,
    ) {}

    /**
     * @param list<UsageStoreRow> $stores
     */
    public static function from(array $stores, UsageCalculator $calculator): self
    {
        $bytes = 0;
        $files = VectorStoreFileCounts::zero();
        $statuses = [];

        foreach ($stores as $store) {
            $bytes += $store->usageBytes;
            $files = $files->plus($store->fileCounts);
            // Bucket by whatever the provider said, including a status this code has never seen. An
            // unknown status must show up as its own bucket rather than be dropped from the tally.
            $statuses[$store->status] = ($statuses[$store->status] ?? 0) + 1;
        }

        return new self(
            storeCount: count($stores),
            storeStatusCounts: $statuses,
            totalUsageBytes: $bytes,
            fileCounts: $files,
            totalGib: $calculator->gibFromBytes($bytes),
            billableGib: $calculator->billableGib($bytes),
            estimatedDailyCost: $calculator->estimatedDailyStorageCost($bytes),
            estimatedProjectedCost: $calculator->estimatedProjectedStorageCost($bytes),
        );
    }

    public function storesWithStatus(string $status): int
    {
        return $this->storeStatusCounts[$status] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'store_count' => $this->storeCount,
            'store_status_counts' => $this->storeStatusCounts,
            'total_usage_bytes' => $this->totalUsageBytes,
            'file_counts' => $this->fileCounts->toArray(),
            'total_gib' => $this->totalGib,
            'billable_gib' => $this->billableGib,
            'estimated_daily_cost' => $this->estimatedDailyCost,
            'estimated_projected_cost' => $this->estimatedProjectedCost,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $statuses = [];
        foreach (SnapshotData::array($data, 'store_status_counts') as $status => $count) {
            if (is_numeric($count)) {
                $statuses[(string) $status] = (int) $count;
            }
        }

        return new self(
            storeCount: SnapshotData::int($data, 'store_count'),
            storeStatusCounts: $statuses,
            totalUsageBytes: SnapshotData::int($data, 'total_usage_bytes'),
            fileCounts: VectorStoreFileCounts::fromArray(SnapshotData::array($data, 'file_counts')),
            totalGib: SnapshotData::float($data, 'total_gib'),
            billableGib: SnapshotData::float($data, 'billable_gib'),
            estimatedDailyCost: SnapshotData::float($data, 'estimated_daily_cost'),
            estimatedProjectedCost: SnapshotData::float($data, 'estimated_projected_cost'),
        );
    }
}
