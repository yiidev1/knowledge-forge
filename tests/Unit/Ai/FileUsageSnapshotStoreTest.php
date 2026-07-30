<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Usage\UsageCalculator;
use App\Ai\Application\Usage\UsageSnapshot;
use App\Ai\Application\Usage\UsageStoreRow;
use App\Ai\Application\Usage\UsageTotals;
use App\Ai\Infrastructure\Usage\FileUsageSnapshotStore;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function bin2hex;
use function file_get_contents;
use function fileinode;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The snapshot file: round-tripping, the stable lock, atomic replacement, and refusing to serve data
 * it does not understand.
 */
final class FileUsageSnapshotStoreTest extends Unit
{
    private string $directory;
    private string $path;
    private string $lockPath;

    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/kf-usage-test-' . bin2hex(random_bytes(6));
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $this->path = $this->directory . '/snapshot.json';
        $this->lockPath = $this->directory . '/snapshot.lock';
    }

    protected function _after(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->directory);
    }

    public function testRoundTripsASnapshot(): void
    {
        $store = $this->store();
        $store->save($this->snapshot());

        $loaded = $store->latest();

        assertNotNull($loaded);
        assertCount(1, $loaded->stores);
        assertSame('vs_abc123', $loaded->stores[0]->id);
        assertSame(2048, $loaded->stores[0]->usageBytes);
        assertSame(4, $loaded->stores[0]->fileCounts->total);
        assertSame('2026-07-29T10:00:00+00:00', $loaded->syncedAt->format(DateTimeImmutable::ATOM));
    }

    public function testNothingSavedYetReadsAsNull(): void
    {
        assertNull($this->store()->latest());
    }

    /**
     * A corrupt cache must degrade the page to "not synced yet", never break it with a parse error.
     */
    public function testCorruptFileReadsAsNull(): void
    {
        file_put_contents($this->path, '{ this is not json');

        assertNull($this->store()->latest());
    }

    /**
     * A snapshot written by a different shape is discarded rather than coerced — half-understood data
     * would render as confidently wrong numbers.
     */
    public function testSnapshotFromAnotherSchemaVersionIsDiscarded(): void
    {
        file_put_contents($this->path, '{"schema_version": 999, "synced_at": "2026-07-29T10:00:00+00:00"}');

        assertNull($this->store()->latest());
    }

    /**
     * Correction 4: the lock is a fixed path, not the per-write temporary file. Two writes must contend
     * on the SAME inode, and the second must completely replace the first.
     */
    public function testUsesAStableLockFileAndReplacesAtomically(): void
    {
        $store = $this->store();

        $store->save($this->snapshot());
        assertFileExists($this->lockPath);
        $lockInode = fileinode($this->lockPath);

        $store->save($this->snapshot(storeId: 'vs_second', bytes: 4096));

        // Same lock file across both writes: a per-write temp lock would have a different inode here,
        // and two concurrent syncs would never have contended at all.
        assertSame($lockInode, fileinode($this->lockPath));

        $loaded = $store->latest();
        assertNotNull($loaded);
        assertSame('vs_second', $loaded->stores[0]->id);
        assertSame(4096, $loaded->stores[0]->usageBytes);
    }

    /**
     * The write is temp-then-rename, so no partial file is ever left behind for a reader to pick up.
     */
    public function testLeavesNoTemporaryResidue(): void
    {
        $this->store()->save($this->snapshot());

        assertSame([], glob($this->directory . '/*.tmp'));
        assertFileExists($this->path);
    }

    /**
     * The file is read back by the web tier, so it is a disclosure surface. Nothing key-shaped may
     * appear in it.
     */
    public function testPersistedFileContainsNoCredentialShapedContent(): void
    {
        $this->store()->save($this->snapshot());

        $raw = (string) file_get_contents($this->path);

        assertStringNotContainsString('sk-', $raw);
        assertStringNotContainsString('Authorization', $raw);
        assertStringNotContainsString('api_key', $raw);
        assertStringNotContainsString('apiKey', $raw);
    }

    private function store(): FileUsageSnapshotStore
    {
        return new FileUsageSnapshotStore($this->path, $this->lockPath);
    }

    private function snapshot(string $storeId = 'vs_abc123', int $bytes = 2048): UsageSnapshot
    {
        $rows = [
            new UsageStoreRow(
                id: $storeId,
                name: 'kf-1-test',
                status: 'completed',
                usageBytes: $bytes,
                fileCounts: new VectorStoreFileCounts(4, 4, 0, 0, 0),
                createdAt: 1785000000,
                lastActiveAt: 1785100000,
                expiresAt: null,
                metadata: ['operation_key' => 'op-1'],
            ),
        ];

        return new UsageSnapshot(
            syncedAt: new DateTimeImmutable('2026-07-29 10:00:00', new DateTimeZone('UTC')),
            stores: $rows,
            totals: UsageTotals::from($rows, new UsageCalculator()),
            mappings: [],
        );
    }
}
