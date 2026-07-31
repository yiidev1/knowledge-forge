<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Order58\Domain\SyncRun;
use DateTimeImmutable;

/**
 * Processes one claimed synchronization run: fetches pages, mirrors records, generates documents, and
 * returns a {@see SyncOutcome} the drainer persists. Handlers never talk to OpenAI and never mark records
 * inactive before their scan's final page has completed.
 */
interface Order58SyncHandlerInterface
{
    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome;
}
