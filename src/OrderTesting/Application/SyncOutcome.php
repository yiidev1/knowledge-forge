<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

/**
 * What one request to sync demo orders produced.
 *
 * Three outcomes, because there are three honest answers: it ran, somebody else is already running
 * it, or there is nowhere to read from. A caller that could not tell "nothing changed" from "the lock
 * was held" would report success for a pass that did nothing at all.
 */
final readonly class SyncOutcome
{
    private function __construct(
        public ?ImportReport $report,
        /** Set only when the directory could not be used. Operator-facing. */
        public ?string $problem = null,
        public bool $busy = false,
    ) {}

    public static function ran(ImportReport $report): self
    {
        return new self($report);
    }

    /** Another holder has the lock — the console command, or a second click. */
    public static function alreadyRunning(): self
    {
        return new self(null, null, true);
    }

    public static function unusable(string $problem): self
    {
        return new self(null, $problem);
    }

    public function didRun(): bool
    {
        return $this->report !== null;
    }

    /** Whether a run that happened actually changed anything. */
    public function changedSomething(): bool
    {
        return $this->report !== null && ($this->report->inserted > 0 || $this->report->updated > 0);
    }
}
