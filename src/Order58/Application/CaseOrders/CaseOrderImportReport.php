<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

/**
 * What one import did, in the terms the sync screen reports and the run history stores.
 *
 * Inserted and updated are counted apart because they answer different questions: inserted is "how
 * much is new today", updated is "how much moved". Skipped and failed are also apart — a line the
 * provider sent that this application chose not to keep is not the same as one it could not keep.
 */
final class CaseOrderImportReport
{
    public function __construct(
        /** Orders in the response that could be read at all. */
        public int $received = 0,
        public int $inserted = 0,
        public int $updated = 0,
        /** Orders the provider returned whose stored copy was already identical, byte for byte. */
        public int $unchanged = 0,
        /** Read, but deliberately not stored — a malformed entry, or a line naming another order. */
        public int $skipped = 0,
        public int $itemsImported = 0,
        /** Read and meant to be stored, but the write failed. */
        public int $failed = 0,
        /** @var list<string> one safe, human-readable line per problem. Never a secret. */
        public array $problems = [],
    ) {}

    /** @param list<string> $problems */
    public function addSkipped(array $problems): void
    {
        $this->skipped += count($problems);
        // Bounded: a pathological response must not put ten thousand lines in a TEXT column.
        $this->problems = array_slice([...$this->problems, ...$problems], 0, 50);
    }

    public function addFailure(string $problem): void
    {
        ++$this->failed;
        $this->problems = array_slice([...$this->problems, $problem], 0, 50);
    }

    /** Orders actually written, which is what "processed" means on the progress bar. */
    public function processed(): int
    {
        return $this->inserted + $this->updated;
    }
}
