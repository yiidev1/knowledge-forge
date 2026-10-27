<?php

declare(strict_types=1);

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Order58\Domain\CaseOrders\CaseOrderSyncStatus;
use App\Order58\Web\CallRecordings\CallRecordingsAsset;
use App\Shared\Audio\RecordingAcquisition;
use App\Shared\Audio\RecordingAcquisitionState;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var array<int, string> $stores source id => name, active stores only
 * @var int|null $selectedStore
 * @var bool $loaded whether the provider was asked for calls on this request
 * @var string $today the current business date, as the date field's ceiling
 * @var list<CallSummary> $calls the chosen day's calls, already filtered
 * @var array<string, RecordingAcquisition> $statuses keyed by call session id
 * @var string $statusUrl the one endpoint this page polls for every visible call at once
 * @var string $historyUrl what has been downloaded before, on its own page
 * @var string $caseOrderSyncUrl where the case order synchronisation is posted
 * @var string $caseOrderStatusUrl what the sync panel polls while a run is in flight
 * @var string $loadUrl where the store picker posts — see the form below
 * @var string $caseOrdersUrl the admin-only Case Orders listing
 * @var CaseOrderSyncRun|null $caseOrderRun the latest run to report on, in flight or finished
 * @var string|null $caseOrderBlockedReason why a run cannot start now, or null when one can
 * @var list<string> $caseOrderProblems configuration faults, in words an operator can act on
 * @var bool $caseOrderConfirmOpen whether to render the confirmation already open, for the scriptless path
 * @var string|null $problem why there are no calls to show
 * @var string $businessDate the day being shown — today unless the operator chose another
 * @var bool $importEnabled
 * @var bool $usingFixtures
 * @var string $source
 */

$assetManager->register(CallRecordingsAsset::class);

$this->setTitle('Order58 Call Recordings');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Order58 Call Recordings'],
]);

$csrfField = (string) $csrf->hiddenInput();
$pageUrl = $urlGenerator->generate('order58.call-recordings');
$downloadUrl = $urlGenerator->generate('order58.call-recordings.download');
/*
 * Whether a run could start right now, and what to say when it could not.
 *
 * Three different answers, and they are not interchangeable:
 *
 *  - nothing configured  -> the button is disabled and names what is missing
 *  - a run already in flight, or the provider itself has told us to wait -> disabled, and says which
 *  - neither             -> the button is offered, however recently the last run finished
 *
 * The answer comes from the coordinator, which is the only thing that knows the rate and lease rules;
 * the panel below shows the latest run regardless, so a run existing is not a reason to refuse one.
 * Resolved once here because the confirmation dialog, the button and the panel all have to agree, and
 * three separate `if`s would be three chances to disagree.
 */
$caseOrderConfigured = $caseOrderProblems === [];
$caseOrderRunnable = $caseOrderBlockedReason === null;

// Why it cannot run, for the `title` on the inert control. The coordinator's own sentence, so the
// tooltip and the refusal an operator would get from pressing it cannot say different things.
$caseOrderUnavailableReason = $caseOrderBlockedReason ?? '';

// `?confirm-case-orders=1` is answered with the dialog open, so the confirmation works scriptless.
// Every other parameter the page is holding rides along, or cancelling would lose the operator's day.
$caseOrderPageArguments = ($selectedStore === null ? [] : ['store' => (string) $selectedStore])
    + ($loaded ? ['load' => '1'] : [])
    + ['date' => $businessDate]
    + ($source === '' ? [] : ['source' => $source]);

$caseOrderConfirmUrl = $urlGenerator->generate(
    'order58.call-recordings',
    ['confirm-case-orders' => '1'] + $caseOrderPageArguments,
);

// Cancel goes back to the same page without the flag, so it loses nothing the operator had chosen.
$pageDismissUrl = $urlGenerator->generate('order58.call-recordings', $caseOrderPageArguments);

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

// "Sync demo orders": the same endpoint and the same importer service as the Order Testing picker's
// button, so there is one importer, one lock and one set of rules about a malformed document. Only the
// `return` field differs, which brings the operator back here with this store and day still loaded —
// `load` matters as much as `store`, because without it the page renders its picker and no calls.
//
// The import itself stays global: one configured directory, with each document naming its own store,
// so there is nothing here to scope it by.
//
// The route NAME as a literal, not a constant: `OrderTestingRoute` lives in the Audio-to-Text module,
// which ModuleIsolationTest forbids this file from naming. A route name is not a namespace.
$demoSyncUrl = $urlGenerator->generate('order-testing.sync-demo-orders');

/**
 * One channel as a step: its mark, the provider's name for it, and where it has got to.
 *
 * The mark is the transcription panel's own — ○ pending, ◉ active, ✓ complete, ✗ failed, – skipped —
 * so a reader scanning the column sees the shape of the call before reading a word of it.
 */
$channelRow = static function (string $channel, RecordingAcquisitionState $state): string {
    $name = RecordingChannel::fromStorage($channel)?->label() ?? $channel;

    return '<li class="o58-channel" data-o58-channel="' . Html::encode($channel) . '"'
        . ' data-state="' . Html::encode($state->step()) . '">'
        . '<span class="o58-channel__mark" aria-hidden="true"></span>'
        . '<span class="o58-channel__name">' . Html::encode($name) . '</span>'
        . '<span class="badge badge--' . Html::encode($state->badge()) . '" data-o58-channel-state>'
        . Html::encode($state->label()) . '</span>'
        . '</li>';
};

/**
 * One call's recordings: a compact card, with a progress panel only while something is moving.
 *
 * ## Hierarchy, because the same word four times says nothing
 *
 * The call's identity lives in the columns to the left and is the primary information. Here the overall
 * state is secondary — one normal badge — and the three channels are tertiary, in smaller, quieter
 * badges. Rendering all four at the same weight, which is what this did, left a reader with nothing to
 * look at first and a row of full-bleed green bars shouting "Downloaded" four times.
 *
 * ## The bar is for the thing that is moving
 *
 * Drawn only while a channel is pending or downloading. Once the asking is finished the bar's subject
 * is gone, and a full bar frozen on every settled row is noise on most of this table, most of the time.
 *
 * ## Two numbers, because one of them would lie
 *
 * The bar counts **channels checked** — how much of the asking is done. The line beside the badge
 * counts **what actually arrived**. A bar tracking availability would sit a third full for ever on a
 * merchant who only records the mixed call; a bar with no sentence beside it would reach full and read
 * as three recordings downloaded.
 *
 * Rendered by the server for the first paint and by `order58-recordings.js` for every poll after it,
 * from the same model — see {@see RecordingAcquisition}.
 */
$recordingsCell = static function (?RecordingAcquisition $acquisition) use ($channelRow): string {
    if ($acquisition === null) {
        return '<span class="badge badge--muted">Not downloaded</span>';
    }

    $outcome = $acquisition->outcome();

    $html = '<div class="o58-recordings" data-o58-progress>'
        // The state and what came of it, on one line: the strongest thing in the cell, and the summary
        // that qualifies it, read together rather than stacked as two separate claims.
        . '<div class="o58-recordings__head">'
        . '<span class="badge badge--' . Html::encode($outcome->badge()) . '" data-o58-outcome>'
        . Html::encode($outcome->label()) . '</span>'
        . '<span class="o58-recordings__availability" data-o58-availability>'
        . Html::encode($acquisition->availabilityText()) . '</span>'
        . '</div>';

    if ($acquisition->isActive()) {
        $html .= '<div class="o58-panel" data-o58-panel>'
            . '<div class="o58-panel__head">'
            . '<span class="o58-panel__title">Overall progress</span>'
            // `aria-hidden`: the bar below carries the same figure as its label, and announcing it
            // twice is how a progress panel becomes unusable with a screen reader.
            . '<span class="o58-panel__count" data-o58-progress-text aria-hidden="true">'
            . Html::encode($acquisition->progressText()) . '</span>'
            . '</div>'
            // A real <progress>, as the transcription card uses: the browser draws it, announces it and
            // honours reduced-motion without any of that being reimplemented here.
            . '<progress class="o58-panel__bar" max="100" value="' . $acquisition->percentChecked() . '"'
            . ' aria-label="' . Html::encode($acquisition->progressText()) . '" data-o58-bar></progress>'
            . '<p class="o58-panel__detail" role="status" data-o58-current>'
            . Html::encode((string) $acquisition->currentStep()) . '</p>'
            . '</div>';
    }

    $html .= '<ul class="o58-channels" data-o58-channels>';

    foreach ($acquisition->channels as $channel => $state) {
        $html .= $channelRow($channel, $state);
    }

    return $html . '</ul></div>';
};

?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 Call Recordings</h1>
        <p class="page-header__subtitle">
            Download a store's call recordings so they can be listened to. Each call brings down up to
            three separate recordings — the mixed call and each side of it — and they appear on that
            store's Audio to Text page, ready to play. Nothing is transcribed until you ask for it,
            one recording at a time, and only the Customer and Agent sides can be: the mixed recording
            holds both speakers on one track and is kept as the playable original of the call.
        </p>
    </div>
    <?php
    // Up here rather than beside the table, because it leaves this page rather than changing it — the
    // same placement the calls page uses for its own history link.
    ?>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($historyUrl) ?>">View Download History</a>
        <?php
        // The only entry point to the Case Orders page. It is not in any sidebar, so this link is how
        // an administrator reaches it — and Agent and Operation never see it.
        ?>
        <a class="btn btn--secondary" href="<?= Html::encode($caseOrdersUrl) ?>">View Case Orders</a>
        <?php
        /*
         * Case orders, in place of the two-endpoint sync this page used to link to.
         *
         * Confirmed before it is made, because this click SPENDS something: the provider allows one
         * request a day. A link rather than a button, and the server answers `?confirm-case-orders=1`
         * by rendering the dialog already open — the same arrangement the transcription settings
         * dialog uses, and it is what makes the feature work with no script at all. The script
         * upgrades the click to `showModal()`.
         *
         * When it cannot run it is plain text rather than a dead control: there is nothing to click,
         * and the panel below says why. A disabled-looking link that still navigated would be worse
         * than either.
         */
        ?>
        <?php if ($caseOrderRunnable): ?>
            <a class="btn btn--primary" href="<?= Html::encode($caseOrderConfirmUrl) ?>"
               data-o58-case-order-open>Sync Order58 Case Orders</a>
        <?php else: ?>
            <span class="btn btn--secondary" aria-disabled="true"
                  title="<?= Html::encode($caseOrderUnavailableReason) ?>">Sync Order58 Case Orders</span>
        <?php endif; ?>
        <form method="post" action="<?= Html::encode($demoSyncUrl) ?>">
            <?= $csrfField ?>
            <input type="hidden" name="return" value="order58.call-recordings">
            <?php if ($selectedStore !== null): ?>
                <input type="hidden" name="store" value="<?= Html::encode((string) $selectedStore) ?>">
            <?php endif; ?>
            <?php if ($loaded): ?>
                <input type="hidden" name="load" value="1">
            <?php endif; ?>
            <input type="hidden" name="date" value="<?= Html::encode($businessDate) ?>">
            <?php if ($source !== ''): ?>
                <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
            <?php endif; ?>
            <button class="btn btn--secondary" type="submit"
                    data-busy-label="Syncing…">Sync demo orders</button>
        </form>
    </div>
</div>

<?php
/*
 * The case order synchronisation panel, and the dialog that starts it.
 *
 * ## Why there is a panel at all and not just a flash message
 *
 * The work happens inside the POST — a provider call and up to a few hundred imports — so the operator
 * is looking at a pending request for several seconds with nothing to read. The run row is written at
 * each stage, so this panel polls it and says where the work actually is. If the browser gives up on
 * the POST, the row still records what happened and a reload shows it.
 *
 * Rendered by the server first and updated by the script second, in that order deliberately: with no
 * script at all this is still an accurate account of today's run — it just needs a refresh.
 *
 * ## The one thing this panel must get right
 *
 * Whether the request actually reached the provider. A run can fail on either side of the socket, and
 * only that tells an operator whether the counts in front of them describe a real answer or an attempt
 * that never left — so it is stated in words rather than left to be inferred from a status.
 *
 * The classes are the ones this application already uses for a synchronisation in progress
 * (`o58-sync-op`, from the Orders page) and for a short confirmation (`a2t-confirm`, which the store
 * picker's settings dialog uses). Reused rather than restyled: a second set would be a second thing to
 * keep looking like the first.
 */
?>
<?php if (!$caseOrderConfigured): ?>
    <div class="alert alert--warning">
        <strong>Case order synchronisation is not configured.</strong>
        <?= Html::encode(implode(' ', $caseOrderProblems)) ?>
    </div>
<?php endif; ?>

<?php
// The confirmation. `open` is printed server-side for the no-JavaScript path — the trigger above is a
// real link to `?confirm-case-orders=1` — and the script intercepts that click to call `showModal()`
// instead. Escape, the backdrop and the focus trap come from the browser, as in every other dialog here.
?>
<dialog class="a2t-confirm" data-o58-case-order-dialog
        aria-labelledby="o58-case-order-title"<?= $caseOrderConfirmOpen && $caseOrderRunnable ? ' open' : '' ?>>
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
                <?= $csrfField ?>
                <input type="hidden" name="reimport" value="<?= Html::encode((string) $caseOrderRun->id) ?>">
                <button class="btn btn--secondary btn--sm" type="submit" data-busy-label="Importing…">
                    Import the stored response again
                </button>
            </form>
        <?php endif; ?>
    </section>

    <form method="post" action="<?= Html::encode($caseOrderSyncUrl) ?>">
        <?= $csrfField ?>
        <div class="a2t-confirm__actions">
            <?php
            // A link, so the no-JavaScript path has a way back to the page without the dialog. With
            // JavaScript the click is intercepted and simply closes it.
            ?>
            <?php
            // Closing stops the watching, never the run: the work is a row and a worker, not this
            // dialog. A link, so the scriptless path has a way back.
            ?>
            <a class="btn btn--sm" href="<?= Html::encode($pageDismissUrl) ?>"
               data-o58-case-order-cancel>Close</a>
            <button class="btn btn--primary btn--sm" type="submit" data-busy-label="Synchronising…"
                    data-o58-case-order-start>Yes, synchronise now</button>
        </div>
    </form>
</dialog>

<?php if (!$importEnabled): ?>
    <div class="alert alert--warning">
        <strong>Recording download is turned off on this server.</strong>
        Calls can be listed, but nothing will be downloaded until
        <code>ORDER58_RECORDING_IMPORT_ENABLED</code> is set. The external recording service is reachable
        only from a server whose IP the client has allowlisted.
    </div>
<?php endif; ?>

<section class="card">
    <h2 class="card__title">Find a store's calls</h2>

    <?php
    /*
     * A POST, and it redirects straight back to the readable address.
     *
     * It was a GET, which suited a page that only read. Pressing Load Calls can now also ASK for a
     * global case order refresh, and a GET that starts work is a GET that a refresh repeats, a
     * prefetch triggers and a crawler fires. The POST goes to its own action, which asks the
     * coordinator once and redirects; the result page is the same `?store=…&load=1` address as
     * before, so bookmarks, the back button and a plain refresh all behave exactly as they did.
     */
    ?>
    <form method="post" action="<?= Html::encode($loadUrl) ?>" class="store-picker" role="group">
        <?= $csrfField ?>
        <?php if ($source !== ''): ?>
            <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
        <?php endif; ?>
        <label class="util-visually-hidden" for="o58r-store">Store</label>
        <select class="field__control store-picker__select" id="o58r-store" name="store">
            <option value="">Choose a store…</option>
            <?php foreach ($stores as $sourceId => $name): ?>
                <option value="<?= Html::encode((string) $sourceId) ?>"
                    <?= $selectedStore === $sourceId ? ' selected' : '' ?>>
                    <?= Html::encode($name . ' — #' . $sourceId) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
        // Defaults to today and never offers a later day: the provider cannot have recorded a call that
        // has not happened.
        ?>
        <label class="field__label store-picker__label" for="o58r-date">Date</label>
        <input class="field__control store-picker__date" type="date" id="o58r-date" name="date"
            value="<?= Html::encode($businessDate) ?>" max="<?= Html::encode($today) ?>">
        <button class="btn btn--primary" type="submit">Load calls</button>
    </form>

    <?php if ($stores === []): ?>
        <p class="field__hint">
            No active Order58 stores are mirrored yet. Run a store sync from Order58 Data Management first.
        </p>
    <?php endif; ?>
</section>

<?php if ($loaded): ?>
    <section class="card">
        <h2 class="card__title">Calls — <?= Html::encode($businessDate) ?></h2>

        <?php if ($usingFixtures): ?>
            <?php // Never left implicit: a page showing invented calls must say so in plain words.
            ?>
            <div class="alert alert--warning">
                <strong>Local fixtures.</strong> These calls are generated in this repository, not
                fetched from the recording service. Development and test only.
            </div>
        <?php endif; ?>

        <?php if ($problem !== null): ?>
            <p class="field__error"><?= Html::encode($problem) ?></p>
        <?php elseif ($calls === []): ?>
            <div class="empty">
                <div class="empty__title">No calls</div>
                <p class="field__hint">
                    This store has no calls for <?= Html::encode($businessDate) ?>.
                </p>
            </div>
        <?php else: ?>
            <form method="post" action="<?= Html::encode($downloadUrl) ?>">
                <?= $csrfField ?>
                <input type="hidden" name="store" value="<?= Html::encode((string) $selectedStore) ?>">
                <?php /* The day these rows are FROM. Without it the request re-fetches today and
                         refuses every selection made on any other date. */ ?>
                <input type="hidden" name="date" value="<?= Html::encode($businessDate) ?>">
                <?php if ($source !== ''): ?>
                    <input type="hidden" name="source" value="<?= Html::encode($source) ?>">
                <?php endif; ?>

                <?php
                // The polling hooks. `data-o58-active` decides whether the script starts at all, so a
                // page of finished downloads makes no requests — the commonest case by far, since most
                // visits are to look at a day rather than to download one.
                $anyActive = false;

                foreach ($statuses as $acquisition) {
                    $anyActive = $anyActive || $acquisition->isActive();
                }
                ?>
                <div class="table-wrap o58-wide" data-o58-calls
                    data-o58-status="<?= Html::encode($statusUrl) ?>"
                    data-o58-store="<?= Html::encode((string) $selectedStore) ?>"
                    data-o58-active="<?= $anyActive ? '1' : '0' ?>">
                    <table class="table o58-table">
                        <thead>
                            <tr>
                                <th class="o58-table__select">
                                    <label class="a2t-checkbox">
                                        <input type="checkbox" data-o58-select-all>
                                        <span class="util-visually-hidden">Select every call shown</span>
                                    </label>
                                </th>
                                <th class="o58-table__id">Call session ID</th>
                                <?php
                                // The provider's own string, printed exactly as sent. No zone label: it
                                // carries none, and appending one would be inventing a claim about a
                                // timestamp this application did not generate.
                                ?>
                                <th class="o58-table__time">Call time</th>
                                <th class="o58-table__order">Order ID</th>
                                <th>Recordings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calls as $call): ?>
                                <tr>
                                    <td>
                                        <label class="a2t-checkbox">
                                            <input type="checkbox" name="calls[]"
                                                value="<?= Html::encode($call->callSessionId) ?>">
                                            <span class="util-visually-hidden">
                                                Select call <?= Html::encode($call->callSessionId) ?>
                                            </span>
                                        </label>
                                    </td>
                                    <td><code><?= Html::encode($call->callSessionId) ?></code></td>
                                    <td><?= Html::encode($call->callTime) ?></td>
                                    <td>
                                        <?= $call->orderId === ''
                                            ? '<span class="util-muted">—</span>'
                                            : Html::encode($call->orderId) ?>
                                    </td>
                                    <td data-o58-call="<?= Html::encode($call->callSessionId) ?>">
                                        <?= $recordingsCell($statuses[$call->callSessionId] ?? null) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="field__hint" data-o58-count>No calls selected</p>

                <?php
                // Said once, here, because it is the only thing about this page that surprises people:
                // a selection does not arrive all at once. One recording is fetched at a time, on
                // purpose — downloading sixty files in parallel is how a server runs out of memory and
                // a third party starts answering 429.
                ?>
                <p class="field__hint">
                    Recordings are downloaded one at a time, so a large selection arrives over several
                    minutes. You can leave this page — the download continues on the server.
                </p>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit" <?= $importEnabled ? '' : 'disabled' ?>>
                        Sync Recordings
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>