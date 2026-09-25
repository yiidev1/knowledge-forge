<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\StoreMirror;
use DateTimeImmutable;
use RuntimeException;

/**
 * The one lookup {@see \App\Order58\Application\RecordingCompanyResolver} performs, answered from a map.
 *
 * Everything else throws, in the spirit of the other fakes here: a test that starts depending on another
 * method fails loudly rather than quietly reading a default nobody thought about.
 *
 * @psalm-suppress MissingImmutableAnnotation the interface is not readonly
 */
final class OneStoreMirror implements Order58StoreRepositoryInterface
{
    /** @param array<int, StoreMirror> $stores keyed by source id */
    public function __construct(private array $stores = []) {}

    /** A store whose mirrored company code is exactly `$company` — the value under test. */
    public static function withCompany(int $sourceId, ?string $company): self
    {
        return new self([$sourceId => new StoreMirror(
            id: 1,
            sourceId: $sourceId,
            name: 'Fixture store',
            company: $company,
            active: true,
            syncHash: str_repeat('0', 64),
            sourceUpdatedAt: null,
            snapshot: [],
            syncedAt: null,
        )]);
    }

    /** No store at all, for the tampered-or-stale-select case. */
    public static function empty(): self
    {
        return new self();
    }

    public function findBySourceId(int $sourceId): ?StoreMirror
    {
        return $this->stores[$sourceId] ?? null;
    }

    public function findSyncHash(int $sourceId): ?string
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function allMirrors(): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function setActive(int $sourceId, bool $active, DateTimeImmutable $now): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function save(StoreMirror $store, int $runId, DateTimeImmutable $now): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markSeen(int $sourceId, int $runId, DateTimeImmutable $now): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function deactivateNotSeen(int $runId, DateTimeImmutable $now): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countAll(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countActive(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }
}
