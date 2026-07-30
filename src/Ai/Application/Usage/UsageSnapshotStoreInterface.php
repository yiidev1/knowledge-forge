<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

/**
 * Persistence for the usage snapshot.
 *
 * `latest()` returning null means "never synced" or "the stored snapshot is unreadable/superseded" —
 * both render as the same empty state, so a corrupt cache degrades the page instead of breaking it.
 */
interface UsageSnapshotStoreInterface
{
    public function latest(): ?UsageSnapshot;

    public function save(UsageSnapshot $snapshot): void;
}
