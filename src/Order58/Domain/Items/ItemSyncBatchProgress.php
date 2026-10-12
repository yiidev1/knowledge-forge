<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use function max;
use function min;
use function round;

/**
 * One operation's progress, as the page reports it.
 *
 * Every number here is read from the enrolment rows at the moment it is asked for. None of it is
 * stored as a running total, because a running total and a set of rows disagree the first time a
 * process is killed between the two writes.
 */
final readonly class ItemSyncBatchProgress
{
    public function __construct(
        public int $batchId,
        public string $triggerSource,
        public BatchOutcome $outcome,
        /** Orders the provider returned for the requested scope. */
        public int $ordersReceived,
        /** Order headers written — the first half of the operation. */
        public int $ordersSaved,
        /** Orders this batch enrolled. The denominator for item progress. */
        public int $enrolled,
        /** Enrolled orders whose items this batch is waiting on. */
        public int $pending,
        /** Settled with items stored, confirmed empty, or already current. */
        public int $done,
        public int $failed,
        /** Enrolled, needed no request: already synchronised when this batch began. */
        public int $skipped,
        /** The Orders Sync ran out of time before covering its whole range. */
        public bool $ordersTruncated,
        public ?string $ordersError,
        public ?string $startedAt,
        public ?string $finishedAt,
        /** True when nothing has settled for a while and the batch is still open. */
        public bool $stalled,
    ) {}

    /** Item work still outstanding. Never negative, whatever the rows say. */
    public function remaining(): int
    {
        return max(0, $this->pending);
    }

    public function percent(): int
    {
        if ($this->enrolled === 0) {
            return $this->outcome->isFinished() ? 100 : 0;
        }

        $settled = $this->enrolled - $this->pending;
        $percent = (int) round($settled / $this->enrolled * 100);

        // One outstanding order must not round up to a finished-looking 100%.
        return $this->pending > 0 ? min($percent, 99) : 100;
    }

    /** What the panel says it is doing right now. */
    public function state(): string
    {
        if ($this->outcome->isFinished()) {
            return $this->outcome->label();
        }

        if ($this->stalled) {
            // Deliberately not "failed": nothing is known to be wrong, only that nothing has moved.
            return 'Waiting for the importer';
        }

        return $this->pending > 0 ? 'Synchronizing order items' : 'Finishing';
    }
}
