<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * A mirrored Order58 store. Used both as the write payload the mapper produces from an API record and as
 * the read model the repository hydrates for display (`id`/`syncedAt` are null on the write path).
 *
 * `snapshot` is a curated, credential-free subset of the source record, stored as `snapshot_json`.
 */
final readonly class StoreMirror
{
    /**
     * @param array<array-key, mixed> $snapshot
     */
    public function __construct(
        public ?int $id,
        public int $sourceId,
        public string $name,
        public ?string $company,
        public bool $active,
        public string $syncHash,
        public ?DateTimeImmutable $sourceUpdatedAt,
        public array $snapshot,
        public ?DateTimeImmutable $syncedAt = null,
        /**
         * The store's hostname, already normalised, or null when the source gave nothing usable.
         *
         * Last and defaulted so every existing construction site keeps compiling; they all pass named
         * arguments, so position carries no meaning here anyway.
         *
         * Null means "the source said nothing I could use", never "this store has no host". The two are
         * worth keeping apart: the write path treats null as *leave what is already stored alone*, so a
         * malformed or absent field can never blank a host that was previously good.
         */
        public ?string $host = null,
    ) {}
}
