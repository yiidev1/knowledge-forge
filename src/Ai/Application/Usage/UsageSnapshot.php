<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use DateTimeImmutable;
use DateTimeZone;

use function array_map;

/**
 * A point-in-time, sanitized view of the OpenAI vector-store inventory, ready to render or persist.
 *
 * What is deliberately NOT in here: API keys, Authorization headers, document contents, file text, and
 * anything else that would turn a cache file into a disclosure. The snapshot is written to disk and
 * read back by the web tier, so its shape IS the security boundary — if a field would be unsafe in a
 * file readable by the web user, it does not belong on this object.
 *
 * `schemaVersion` is checked on read. A snapshot written by an older shape is discarded rather than
 * coerced, because a half-understood cache renders as confidently wrong numbers.
 */
final readonly class UsageSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<UsageStoreRow> $stores
     * @param list<UsageMapping>  $mappings
     * @param list<SyncProblem>   $problems Per-source failures; an empty list means everything succeeded.
     */
    public function __construct(
        public DateTimeImmutable $syncedAt,
        public array $stores,
        public UsageTotals $totals,
        public array $mappings,
        public array $problems = [],
        /**
         * True when a page or time bound stopped the sweep early. The figures are then a floor, not a
         * total, and the page must say so rather than presenting a partial sweep as complete.
         */
        public bool $truncated = false,
        public bool $adminApiConfigured = false,
    ) {}

    public function hasProblems(): bool
    {
        return $this->problems !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'synced_at' => $this->syncedAt->format(DateTimeImmutable::ATOM),
            'truncated' => $this->truncated,
            'admin_api_configured' => $this->adminApiConfigured,
            'totals' => $this->totals->toArray(),
            'stores' => array_map(static fn(UsageStoreRow $s): array => $s->toArray(), $this->stores),
            'mappings' => array_map(static fn(UsageMapping $m): array => $m->toArray(), $this->mappings),
            'problems' => array_map(static fn(SyncProblem $p): array => $p->toArray(), $this->problems),
        ];
    }

    /**
     * Rebuilds a snapshot from its persisted form, or returns null when the payload is not a snapshot
     * this version understands.
     *
     * Returning null rather than throwing is deliberate: a corrupt or superseded cache file must degrade
     * the page to "not synced yet", never break it.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        $raw = SnapshotData::nullableString($data, 'synced_at');
        $syncedAt = $raw === null
            ? false
            : DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $raw);

        if ($syncedAt === false) {
            return null;
        }

        $rows = [];
        foreach (SnapshotData::rows($data, 'stores') as $row) {
            $rows[] = UsageStoreRow::fromArray($row);
        }

        $mappings = [];
        foreach (SnapshotData::rows($data, 'mappings') as $row) {
            $mappings[] = UsageMapping::fromArray($row);
        }

        $problems = [];
        foreach (SnapshotData::rows($data, 'problems') as $row) {
            $problems[] = SyncProblem::fromArray($row);
        }

        return new self(
            syncedAt: $syncedAt->setTimezone(new DateTimeZone('UTC')),
            stores: $rows,
            totals: UsageTotals::fromArray(SnapshotData::array($data, 'totals')),
            mappings: $mappings,
            problems: $problems,
            truncated: SnapshotData::bool($data, 'truncated'),
            adminApiConfigured: SnapshotData::bool($data, 'admin_api_configured'),
        );
    }
}
