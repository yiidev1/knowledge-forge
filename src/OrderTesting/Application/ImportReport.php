<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use function count;
use function sprintf;

/**
 * What one import run did.
 *
 * Counts only. Nothing here carries a customer name, a phone number or a payload — a report is printed
 * to a console and written to a log, and both outlive the run.
 */
final class ImportReport
{
    public int $scanned = 0;
    public int $inserted = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $refused = 0;
    public int $unreadable = 0;
    public int $matched = 0;
    public int $expired = 0;

    /** @var list<string> filename and gate, for the console. Never the document's contents. */
    public array $refusals = [];

    public function refuse(string $filename, string $reason): void
    {
        ++$this->refused;

        // Bounded: one bad batch must not turn a report into a log flood.
        if (count($this->refusals) < 50) {
            $this->refusals[] = $filename . ': ' . $reason;
        }
    }

    public function summary(): string
    {
        return sprintf(
            'scanned %d, inserted %d, updated %d, unchanged %d, refused %d, unreadable %d, '
            . 'matched to an attempt %d, attempts expired %d',
            $this->scanned,
            $this->inserted,
            $this->updated,
            $this->unchanged,
            $this->refused,
            $this->unreadable,
            $this->matched,
            $this->expired,
        );
    }
}
