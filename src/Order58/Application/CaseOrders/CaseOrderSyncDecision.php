<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;

/**
 * What the coordinator decided about a request to synchronise.
 *
 * Five answers, and only one of them starts anything. The others exist so a caller never has to guess
 * why nothing happened — "already running" and "too soon" look identical from outside and lead to
 * different words on screen.
 */
final readonly class CaseOrderSyncDecision
{
    private function __construct(
        public string $outcome,
        public string $message,
        public ?CaseOrderSyncRun $run = null,
    ) {}

    /** A run was claimed and queued for the worker. */
    public static function started(CaseOrderSyncRun $run): self
    {
        return new self('started', 'Case order synchronisation has started.', $run);
    }

    /** Another run already holds the global claim; this caller watches that one. */
    public static function joined(CaseOrderSyncRun $run): self
    {
        return new self('joined', 'A case order synchronisation is already running.', $run);
    }

    /** A recent run is fresh enough, so nothing was asked of the provider. */
    public static function fresh(CaseOrderSyncRun $run, string $message): self
    {
        return new self('fresh', $message, $run);
    }

    /** The provider's own rate policy, which is separate from freshness. */
    public static function rateLimited(?CaseOrderSyncRun $run, string $message): self
    {
        return new self('rate_limited', $message, $run);
    }

    public static function notAllowed(string $message): self
    {
        return new self('not_allowed', $message);
    }

    /** Whether a worker now has something to do because of this decision. */
    public function startedWork(): bool
    {
        return $this->outcome === 'started';
    }

    /** Whether the caller has a run to watch, whether or not it started it. */
    public function isWatchable(): bool
    {
        return $this->run !== null && ($this->outcome === 'started' || $this->outcome === 'joined');
    }

    public function isError(): bool
    {
        return $this->outcome === 'not_allowed';
    }
}
