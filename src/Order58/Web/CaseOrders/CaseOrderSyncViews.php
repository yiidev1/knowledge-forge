<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

/**
 * Absolute paths to the case order synchronisation's two partials.
 *
 * The trigger and the dialog are rendered by every page that offers "Sync Order58 Case Orders", so the
 * label, the confirmation, the progress panel and the form are one file each rather than a copy per
 * page. Addressed absolutely because a partial rendered from inside a template is not alias-expanded —
 * the same arrangement `AudioToTextViews` and `ReportViews` use.
 */
final class CaseOrderSyncViews
{
    /** The button, or the inert label that says why it cannot be pressed. */
    public static function trigger(): string
    {
        return __DIR__ . '/_sync-trigger';
    }

    /** The confirmation, the live run panel and the form that posts to {@see SyncAction}. */
    public static function dialog(): string
    {
        return __DIR__ . '/_sync-dialog';
    }
}
