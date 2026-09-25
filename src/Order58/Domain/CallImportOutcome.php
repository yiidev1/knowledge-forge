<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * One word for a whole call, derived from the channels it produced.
 *
 * A call is not stored anywhere: it is its own channel rows, grouped. This is the rule that turns them
 * into the single status a row on the page shows, and it is the only such rule — the same shape
 * {@see ConversationStatus::fromChildren()} uses to turn several job statuses into one.
 *
 * The interesting case is `Partial`, and it is the *expected* one rather than an edge: most merchants
 * produce a mixed recording and nothing else, so "Mixed imported, Caller and Callee not available" is
 * what a healthy import of a typical call looks like. Reporting that as anything worse would train an
 * administrator to ignore the column.
 */
enum CallImportOutcome: string
{
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';

    /**
     * @param non-empty-list<Order58ImportStatus> $channels every channel row of one call
     */
    public static function fromChannels(array $channels): self
    {
        $imported = 0;
        $unfinished = 0;
        $shortfall = 0;

        foreach ($channels as $status) {
            if (!$status->isSettled()) {
                $unfinished++;

                continue;
            }

            if ($status === Order58ImportStatus::Imported) {
                $imported++;

                continue;
            }

            // Not available, too large, failed: settled, and not a recording we now hold.
            $shortfall++;
        }

        return match (true) {
            // Anything still moving outranks everything: the answer is not final yet, and saying
            // "Partial" about a call whose caller channel is still downloading would be wrong twice over.
            $unfinished > 0 => self::Processing,
            $imported === 0 => self::Failed,
            $shortfall > 0 => self::Partial,
            default => self::Completed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Partial => 'Partial',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Processing => 'info',
            self::Partial => 'warning',
            self::Failed => 'error',
        };
    }
}
