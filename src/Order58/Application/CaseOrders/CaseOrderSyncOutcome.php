<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;

/**
 * What one attempt to synchronise case orders did, in terms an operator and a JSON endpoint share.
 *
 * ## Four answers, and three of them are not failures
 *
 * ```
 * started      the day was claimed and the work ran; $run says how it went
 * alreadyRun   today is taken. NOT an error — the allowance is one a day and it is spent
 * notAllowed   something is misconfigured or switched off. Nothing was sent
 * failed       it ran and stopped; $run->stage says whether the provider was reached
 * ```
 *
 * `alreadyRun` is separate from `failed` because an operator pressing the button twice has not done
 * anything wrong, and a red error for the second press would teach them to distrust the first.
 */
final readonly class CaseOrderSyncOutcome
{
    private function __construct(
        public string $result,
        public string $message,
        public ?CaseOrderSyncRun $run = null,
    ) {}

    public static function started(CaseOrderSyncRun $run, string $message): self
    {
        return new self('started', $message, $run);
    }

    public static function alreadyRun(?CaseOrderSyncRun $run, string $message): self
    {
        return new self('already_run', $message, $run);
    }

    public static function notAllowed(string $message): self
    {
        return new self('not_allowed', $message);
    }

    public static function failed(?CaseOrderSyncRun $run, string $message): self
    {
        return new self('failed', $message, $run);
    }

    /** Whether the attempt produced imported data. Used to decide what the panel celebrates. */
    public function isSuccess(): bool
    {
        return $this->result === 'started';
    }

    /** Whether this is worth showing in red. `already_run` deliberately is not. */
    public function isError(): bool
    {
        return $this->result === 'failed' || $this->result === 'not_allowed';
    }
}
