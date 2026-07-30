<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use DateTimeImmutable;

/**
 * Records when a sync was last ATTEMPTED, separately from when one last succeeded.
 *
 * These must not be the same thing. The snapshot's timestamp only advances on success, so throttling on
 * it would leave a failing sync completely unthrottled — precisely the case where hammering the provider
 * is both most likely and least useful. The marker is written before the work starts, so an attempt that
 * then fails still counts.
 */
interface SyncAttemptMarkerInterface
{
    public function lastAttemptAt(): ?DateTimeImmutable;

    public function markAttempt(DateTimeImmutable $at): void;
}
