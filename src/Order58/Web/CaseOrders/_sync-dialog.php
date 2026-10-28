<?php

declare(strict_types=1);

use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use Yiisoft\Html\Html;

/**
 * The case order synchronisation dialog — the confirmation, the live run panel and the form.
 *
 * One file for every page that offers "Sync Order58 Case Orders", so the question, the counts, the
 * stages and what is posted cannot differ between them. Everything it posts goes to {@see SyncAction};
 * everything it polls comes from {@see StatusAction}; `order58-case-orders.js` drives it.
 *
 * The classes are the ones this application already uses for a synchronisation in progress
 * (`o58-sync-op`, from the Orders page) and for a short confirmation (`a2t-confirm`, which the store
 * picker's settings dialog uses). Reused rather than restyled: a second set would be a second thing to
 * keep looking like the first.
 *
 * @var string $csrfField
 * @var string $caseOrderSyncUrl where the case order synchronisation is posted
 * @var string $caseOrderStatusUrl what the panel polls while a run is in flight
 * @var CaseOrderSyncRun|null $caseOrderRun the run in flight, or the one this operator just finished
 * @var string|null $caseOrderBlockedReason why a run cannot start now, or null when one can
 * @var bool $caseOrderConfirmOpen whether to render the confirmation already open, for the scriptless path
 * @var string $dismissUrl the rendering page without the confirmation flag, for Close
 * @var string|null $returnRoute where the scriptless POST comes back to; null keeps the endpoint's default
 * @var bool $refreshOnClose reload the rendering page after a run settles, for a page whose own
 *      figures a synchronisation changes
 */

/*
 * The three things the panel's heading and bar need, resolved once.
 *
 * Here rather than inline because "no run today" is a state of the page, not an absent value to be
 * coalesced at each of three use sites — and a `?->` chain feeding a `??` in markup reads as plumbing
 * rather than as the sentence it is.
 */
$caseOrderStateLabel = $caseOrderRun === null ? 'Not run today' : $caseOrderRun->status->label();
$caseOrderStateBadge = $caseOrderRun === null ? 'idle' : $caseOrderRun->status->modifier();
$caseOrderPercent = $caseOrderRun === null ? 0 : $caseOrderRun->status->percent();

// Sent with both forms, so the scriptless redirect lands back on the page that was pressed. A route
// name from the endpoint's own allow-list, never a URL — see SyncAction::returnRoute().
$returnField = $returnRoute === null
    ? ''
    : '<input type="hidden" name="return" value="' . Html::encode($returnRoute) . '">';
?>
<?php
// The confirmation. `open` is printed server-side for the no-JavaScript path — the trigger above is a
// real link to `?confirm-case-orders=1` — and the script intercepts that click to call `showModal()`
// instead. Escape, the backdrop and the focus trap come from the browser, as in every other dialog here.
?>
<dialog class="a2t-confirm" data-o58-case-order-dialog<?= $refreshOnClose ? ' data-o58-case-order-refresh' : '' ?>
        aria-labelledby="o58-case-order-title"<?= $caseOrderConfirmOpen && $caseOrderBlockedReason === null ? ' open' : '' ?>>
    <h2 class="a2t-confirm__title" id="o58-case-order-title">Synchronise Order58 case orders?</h2>

    <p class="a2t-confirm__note">
        This makes <strong>one request to the provider</strong>, returning recent orders together with
        their lines and importing them here. You may run it as often as you need; each run is recorded
        separately in the history.
    </p>
    <?php
    /*
     * The three facts, worded to avoid the words this page's own columns use.
     *
     * "Failed" and "Partial" are channel statuses in the recordings table below, and this dialog is
     * rendered on every load — so a sentence here containing one of them makes the page claim a
     * recording state it is not describing. `Order58CallRecordingsCest` asserts the absence of those
     * words for exactly that reason, and it caught this copy.
     */
?>
    <ul class="a2t-confirm__note">
        <li>Orders already stored are updated; orders missing from the response are left alone.</li>
        <li>Nothing is deleted, and an attempt that stops part-way changes nothing.</li>
        <li>If the import stops after the response arrives, it can be run again without another request.</li>
        <li>Only one synchronisation runs at a time; pressing again while one is going watches that one.</li>
    </ul>

    <?php
/*
 * The progress lives HERE now, not in a panel on the page behind.
 *
 * A panel below the button reported a run the operator had to go and look for, while the dialog
 * they pressed in closed and told them nothing. Everything about one synchronisation — the
 * confirmation, the stages and the counts — belongs to one surface.
 *
 * Rendered from the server's own view of the latest run, then updated in place by the script.
 * With no script at all this still shows what happened on the last page load, which is the same
 * guarantee the panel gave.
 */
?>
    <?php
/*
 * Hidden unless there is a run to describe, and `$caseOrderRun` is now only ever the run in
 * flight or the one this operator just finished — never "the most recent". Opening the dialog
 * with nothing running therefore shows the question and the buttons, and no counts at all.
 *
 * `data-o58-case-order-run` is the subject, published so the script polls that run by id rather
 * than asking the server which one it thinks is interesting.
 */
?>
    <section class="o58-sync-op" data-o58-case-order-panel
             data-o58-case-order-status="<?= Html::encode($caseOrderStatusUrl) ?>"
             <?= $caseOrderRun === null ? '' : 'data-o58-case-order-run="' . Html::encode((string) $caseOrderRun->id) . '"' ?>
             data-o58-case-order-active="<?= $caseOrderRun !== null && !$caseOrderRun->status->isFinished() ? '1' : '0' ?>"
             <?= $caseOrderRun === null ? 'hidden' : '' ?>>
        <div class="o58-sync-op__heading">
            <span class="badge badge--<?= Html::encode($caseOrderStateBadge) ?>"
                  data-o58-case-order-state><?= Html::encode($caseOrderStateLabel) ?></span>
        </div>

        <?php
    // A real <progress>, and a STAGE marker rather than an invented percentage: three of the four
    // steps are single operations with no observable interior. See CaseOrderSyncStatus::percent().
?>
        <progress class="o58-sync-op__bar" data-o58-case-order-bar max="100"
                  value="<?= Html::encode((string) $caseOrderPercent) ?>"></progress>

        <p class="o58-sync-op__detail" data-o58-case-order-counts>
            <span class="badge badge--muted" data-o58-case-order-received>Received: <?= Html::encode((string) ($caseOrderRun?->ordersReceived ?? 0)) ?></span>
            <span class="badge badge--success" data-o58-case-order-inserted>New: <?= Html::encode((string) ($caseOrderRun?->ordersInserted ?? 0)) ?></span>
            <span class="badge badge--muted" data-o58-case-order-updated>Updated: <?= Html::encode((string) ($caseOrderRun?->ordersUpdated ?? 0)) ?></span>
            <span class="badge badge--muted" data-o58-case-order-unchanged>Unchanged: <?= Html::encode((string) ($caseOrderRun?->ordersUnchanged ?? 0)) ?></span>
            <span class="badge badge--muted" data-o58-case-order-items>Lines: <?= Html::encode((string) ($caseOrderRun?->itemsImported ?? 0)) ?></span>
            <?php
    // The hook is always present so the script can fill it, but it carries NO TEXT until
    // there is something to report. "Failed" is also a recording status on this page, and a
    // zero-count badge would put the word on every load — which this page's own test
    // (rightly) treats as the page claiming a failed channel.
?>
            <span class="badge badge--error" data-o58-case-order-failed<?= ($caseOrderRun?->failures ?? 0) > 0 ? '' : ' hidden' ?>><?= ($caseOrderRun?->failures ?? 0) > 0 ? 'Failed: ' . Html::encode((string) $caseOrderRun?->failures) : '' ?></span>
        </p>

        <p class="o58-sync-op__note" data-o58-case-order-message<?= $caseOrderRun?->errorSummary === null ? ' hidden' : '' ?>>
            <?= Html::encode($caseOrderRun?->errorSummary ?? '') ?>
        </p>

        <?php
        // The question an operator actually has after a failure: did the request reach the provider?
        // Only the stage knows — a run can fail on either side of the socket. See CaseOrderSyncRun.
        // It no longer decides whether another run may start; that is the coordinator's answer, and
        // for a manual press it is almost always yes.
?>
        <p class="o58-sync-op__scope" data-o58-case-order-allowance>
            <?php if ($caseOrderRun === null): ?>
                Case orders are fetched globally: one request returns recent orders for every store,
                together with their lines.
            <?php elseif (!$caseOrderRun->status->isFinished()): ?>
                Running for <?= Html::encode($caseOrderRun->businessDay) ?>. Leaving this page does not
                stop it; the result is recorded either way.
            <?php elseif ($caseOrderRun->allowanceSpent()): ?>
                The provider was contacted for this run. You can synchronise again whenever you need
                to; each run is recorded separately.
            <?php else: ?>
                This attempt stopped before the provider was contacted, so nothing was asked of it.
            <?php endif; ?>
        </p>

        <?php if ($caseOrderRun !== null && $caseOrderRun->canRetryWithoutCallingTheProvider()): ?>
            <?php
    // Offered only when it is free: the response that arrived is still stored, so this
    // imports it again without contacting anybody.
            ?>
            <form method="post" action="<?= Html::encode($caseOrderSyncUrl) ?>">
                <?= $csrfField ?><?= $returnField ?>
                <input type="hidden" name="reimport" value="<?= Html::encode((string) $caseOrderRun->id) ?>">
                <button class="btn btn--secondary btn--sm" type="submit" data-busy-label="Importing…">
                    Import the stored response again
                </button>
            </form>
        <?php endif; ?>
    </section>

    <form method="post" action="<?= Html::encode($caseOrderSyncUrl) ?>">
        <?= $csrfField ?><?= $returnField ?>
        <div class="a2t-confirm__actions">
            <?php
            // A link, so the no-JavaScript path has a way back to the page without the dialog. With
            // JavaScript the click is intercepted and simply closes it.
?>
            <?php
// Closing stops the watching, never the run: the work is a row and a worker, not this
// dialog. A link, so the scriptless path has a way back.
?>
            <a class="btn btn--sm" href="<?= Html::encode($dismissUrl) ?>"
               data-o58-case-order-cancel>Close</a>
            <button class="btn btn--primary btn--sm" type="submit" data-busy-label="Synchronising…"
                    data-o58-case-order-start>Yes, synchronise now</button>
        </div>
    </form>
</dialog>
