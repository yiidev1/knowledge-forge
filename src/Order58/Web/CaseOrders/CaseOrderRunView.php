<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;

/**
 * One synchronisation run, as the dialog's script reads it.
 *
 * ## Why this is shared rather than written twice
 *
 * Two endpoints describe a run: the status endpoint the panel polls, and the synchronisation POST,
 * which now answers with the run it just finished. The panel renders whichever arrives, by the same
 * field names — so a second copy of this shape would be a second thing to keep in step, and the first
 * field that drifted would show as a blank badge or a missing count with nothing to explain it.
 *
 * Every value is already sanitized: `errorSummary` is this application's own sentence, never a
 * provider body or a transport message, and no credential or customer detail reaches these fields.
 */
final readonly class CaseOrderRunView
{
    /** @return array<string, mixed>|null */
    public static function describe(?CaseOrderSyncRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'businessDay' => $run->businessDay,
            'status' => $run->status->value,
            'statusLabel' => $run->status->label(),
            'modifier' => $run->status->modifier(),
            'finished' => $run->status->isFinished(),
            'running' => !$run->status->isFinished(),
            'percent' => $run->status->percent(),
            /*
             * The question an operator actually has after a failure: did the request reach the
             * provider? Read off the RUN, not the status. A failure before the socket — unconfigured,
             * or the transport never connected — reached nobody, and only the stage knows that.
             */
            'allowanceSpent' => $run->allowanceSpent(),
            'stage' => $run->stage,
            'httpStatus' => $run->httpStatus,
            'responseBytes' => $run->responseBytes,
            'ordersReceived' => $run->ordersReceived,
            'ordersInserted' => $run->ordersInserted,
            'ordersUpdated' => $run->ordersUpdated,
            'ordersSkipped' => $run->ordersSkipped,
            'ordersUnchanged' => $run->ordersUnchanged,
            'triggerSource' => $run->triggerSource,
            'itemsImported' => $run->itemsImported,
            'failures' => $run->failures,
            'message' => $run->errorSummary,
            'canReimport' => $run->canRetryWithoutCallingTheProvider(),
            'startedAt' => $run->startedAt->format('c'),
            'completedAt' => $run->completedAt?->format('c'),
        ];
    }
}
