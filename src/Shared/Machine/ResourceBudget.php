<?php

declare(strict_types=1);

namespace App\Shared\Machine;

/**
 * What one particular kind of background work considers enough machine to start on.
 *
 * ## Why this is a parameter rather than a setting
 *
 * Two workers on the same server need wildly different answers, and a single global threshold gets one of
 * them wrong. A transcription peaks near 900 MB, so it should wait for roughly that much again; a
 * recording download peaks near 90 MB, so making it wait for a gigabyte would stall imports in exactly
 * the conditions where the queue most needs feeding — and stall them indefinitely, because the guard
 * fails closed.
 *
 * Passing the budget in keeps {@see ResourceAdmission} free of any module's configuration, which is what
 * lets it live in `Shared` at all.
 *
 * ## Sizing one honestly
 *
 * Both numbers should come from a measurement, and the measurement should be of the work, not of the
 * machine that happens to be handy. A threshold derived from a developer's 16 GB laptop is a threshold
 * that can be impossible to satisfy on a 4 GB production server, which presents as a queue that never
 * moves rather than as an error anyone notices.
 *
 * The load figure is per core precisely so it does *not* need re-deriving per host.
 */
final readonly class ResourceBudget
{
    /**
     * @param int   $minAvailableMegabytes memory that must be available before work starts; measured
     *                                     peak of the work plus margin, never a fraction of total RAM
     * @param float $maxLoadPerCore        one-minute load average per logical CPU above which work waits
     */
    public function __construct(
        public int $minAvailableMegabytes,
        public float $maxLoadPerCore,
    ) {}
}
