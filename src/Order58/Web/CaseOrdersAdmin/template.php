<?php

declare(strict_types=1);

use App\Order58\Web\CaseOrdersAdmin\CaseOrdersAdminAsset;
use App\Order58\Domain\CaseOrders\CaseOrderListItem;
use App\Order58\Domain\CaseOrders\CaseOrderListQuery;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var CaseOrderListQuery $query
 * @var list<CaseOrderListItem> $items
 * @var array<int, string> $stores
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int $perPage
 * @var list<CaseOrderSyncRun> $runs
 * @var int $runCount
 * @var CaseOrderSyncRun|null $activeRun
 * @var AppTimeZone $appTimeZone
 * @var string $pageUrl
 * @var string $recordingsUrl
 * @var string $detailRoute
 */

$assetManager->register(CaseOrdersAdminAsset::class);

// The detail URL for one row. A closure so the link below stays one line and the route name is
// named once rather than at every call site.
$detailUrl = static fn(CaseOrderListItem $item): string => $urlGenerator->generate($detailRoute, [
    'sourceId' => $item->storeSourceId,
    'sourceOrderId' => $item->sourceOrderId,
]);

$this->setTitle('Order58 Case Orders');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Case Orders'],
]);

/** A value, or a dash. A dash is "we do not have this", which is not the same as zero. */
$dash = static fn(?string $v): string => $v === null || $v === ''
    ? '<span class="util-muted">&mdash;</span>'
    : Html::encode($v);

$when = static fn(?DateTimeImmutable $at): string => $at === null
    ? '<span class="util-muted">&mdash;</span>'
    : Html::encode($appTimeZone->format($at, 'M j, Y g:i A'));

// Page and sort links carry every active filter, so neither throws the reader's narrowing away.
/** @param array<string, string|null> $extra */
$linkWith = static function (array $extra) use ($urlGenerator, $query): string {
    /** @var array<string, string> $params */
    $params = array_filter($extra, static fn(?string $v): bool => $v !== null) + $query->queryParams();

    return $urlGenerator->generate('order58.case-orders', $params);
};

$sortLink = static function (string $key, string $label) use ($linkWith, $query): string {
    $isCurrent = $query->sort === $key;
    // Clicking the current column flips it; clicking another starts that one newest-first.
    $dir = $isCurrent && $query->descending ? 'asc' : 'desc';
    $arrow = $isCurrent ? ($query->descending ? ' ▾' : ' ▴') : '';

    return '<a href="' . Html::encode($linkWith(['sort' => $key, 'dir' => $dir, 'page' => null]))
        . '">' . Html::encode($label) . $arrow . '</a>';
};

$itemsBadge = static fn(string $status): string => match ($status) {
    'SYNCED' => '<span class="badge badge--success">Synced</span>',
    'EMPTY' => '<span class="badge badge--success">No items</span>',
    'FAILED' => '<span class="badge badge--error">Failed</span>',
    'QUEUED', 'PROCESSING' => '<span class="badge badge--info">' . Html::encode($status) . '</span>',
    default => '<span class="util-muted">Not synced</span>',
};

$yesNo = static fn(bool $v): string => $v
    ? '<span class="badge badge--success">Yes</span>'
    : '<span class="util-muted">&mdash;</span>';
?>
<?php
// `o58-wide` opens the content column to 1600px for this page only — see `.content:has(.o58-wide)`.
// A thirteen-column table in a 1240px reading column scrolls inside its box while a third of the
// screen sits empty; this is the same opt-in the store pages use.
?>
<div class="page-header o58-wide">
    <div>
        <h1 class="page-header__title">Case Orders</h1>
        <p class="page-header__subtitle">
            Every original order stored here, across all stores, with the synchronisation history that
            brought them in. Read-only.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($recordingsUrl) ?>">Back to Call Recordings</a>
    </div>
</div>

<section class="card">
    <h2 class="card__title">Filter</h2>

    <?php
    /*
     * A GET form: a filtered listing is a readable, repeatable address, and it starts nothing.
     *
     * Laid out as a grid, not a flex row. `.field__control` is `width: 100%` by design — it is built
     * for stacked fields — so nine of them in a flex row each claimed a whole line, which is what
     * stacked this filter down the page. In a grid that same rule is exactly right: the track sizes
     * the control.
     */
?>
    <form method="get" action="<?= Html::encode($pageUrl) ?>" class="o58-co-filters" role="search">
        <div class="o58-co-filters__store">
            <label class="o58-co-filters__label" for="co-store">Store</label>
            <select class="field__control" id="co-store" name="store">
                <option value="">All stores</option>
                <?php foreach ($stores as $sourceId => $name): ?>
                    <option value="<?= Html::encode((string) $sourceId) ?>"
                        <?= $query->storeSourceId === $sourceId ? ' selected' : '' ?>>
                        <?= Html::encode($name . ' — #' . $sourceId) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="o58-co-filters__label" for="co-q">Order number</label>
            <input class="field__control" id="co-q" name="q" type="search" placeholder="Order number or seq"
                   value="<?= Html::encode($query->search) ?>">
        </div>

        <?php // A from/to pair is one idea, so it occupies one track under one label.?>
        <div class="o58-co-filters__range">
            <span class="o58-co-filters__label">Ordered</span>
            <div class="o58-co-filters__range-inputs">
                <input class="field__control" name="from" type="date" aria-label="Ordered from"
                       value="<?= Html::encode($query->orderedFrom) ?>">
                <input class="field__control" name="to" type="date" aria-label="Ordered to"
                       value="<?= Html::encode($query->orderedTo) ?>">
            </div>
        </div>

        <?php // A different question from the one above: when WE stored it, not when it was placed.?>
        <div class="o58-co-filters__range">
            <span class="o58-co-filters__label">Synced</span>
            <div class="o58-co-filters__range-inputs">
                <input class="field__control" name="sfrom" type="date" aria-label="Synced from"
                       value="<?= Html::encode($query->syncedFrom) ?>">
                <input class="field__control" name="sto" type="date" aria-label="Synced to"
                       value="<?= Html::encode($query->syncedTo) ?>">
            </div>
        </div>

        <?php // Item status, Recording and Demo order are deliberately not offered as controls:
            // the last two are already columns below, and the first is a synchronisation detail
            // rather than something an order is looked up by. The query still understands all
            // three, so a saved URL keeps working and Reset still clears it.?>
        <?php // Actions on their own full-width line, so Apply is never stranded beside a date box.?>
        <div class="o58-co-filters__actions">
            <button class="btn btn--primary" type="submit">Apply</button>
            <?php if ($query->isFiltered()): ?>
                <a class="btn btn--secondary" href="<?= Html::encode($pageUrl) ?>">Reset filters</a>
            <?php endif; ?>
            <span class="field__hint">
                <?= Html::encode((string) $total) ?> order(s)<?= $query->isFiltered() ? ' match' : ' stored' ?>.
            </span>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Orders</h2>

    <?php if ($items === []): ?>
        <p class="util-muted">No case orders match.</p>
    <?php else: ?>
        <?php
    // `o58-table-scroll` keeps a wide table inside its own box, so the PAGE never scrolls
    // sideways; `o58-orders-table` puts each short value on one line and lets the store name —
    // the one column with no natural length — take the slack instead.
        ?>
        <div class="o58-table-scroll">
            <table class="table o58-orders-table">
                <thead>
                    <tr>
                        <th scope="col"><?= $sortLink('order', 'Order') ?></th>
                        <th scope="col"><?= $sortLink('store', 'Store') ?></th>
                        <th scope="col">Account</th>
                        <th scope="col"><?= $sortLink('ordered', 'Ordered') ?></th>
                        <th scope="col">Status</th>
                        <th scope="col">Total</th>
                        <th scope="col">Items</th>
                        <th scope="col">Qty</th>
                        <th scope="col"><?= $sortLink('items', 'Item sync') ?></th>
                        <th scope="col"><?= $sortLink('synced', 'Last synced') ?></th>
                        <th scope="col">Recording</th>
                        <th scope="col">Demo</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php // The order number, so the dialog can title itself without a second read.?>
                        <tr data-o58-co-order="<?= Html::encode((string) $item->sourceOrderId) ?>">
                            <td>
                                <strong><?= Html::encode((string) $item->sourceOrderId) ?></strong>
                                <?php if ($item->seq !== null): ?>
                                    <span class="ot-seq"><?= Html::encode($item->seq) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="o58-cell-store"><?= $dash($item->storeName) ?></td>
                            <td><?= Html::encode((string) $item->storeSourceId) ?></td>
                            <td><?= $when($item->orderedAt()) ?></td>
                            <td><?= $dash($item->status) ?></td>
                            <td><?= $dash($item->totalAmount) ?></td>
                            <td><?= Html::encode((string) $item->itemCount) ?></td>
                            <td><?= Html::encode($item->totalQuantity) ?></td>
                            <td><?= $itemsBadge($item->itemsStatus) ?></td>
                            <td><?= $when($item->lastSyncedAt) ?></td>
                            <td><?= $yesNo($item->hasRecording) ?></td>
                            <td><?= $yesNo($item->hasDemoOrder) ?></td>
                            <td>
                                <?php // A real link, upgraded rather than replaced: the script reads
                                    // this same href, asks for ?fragment=1 and shows it in the
                                    // dialog. One URL, so the two paths cannot drift apart.?>
                                <a class="btn btn--sm btn--secondary" data-o58-co-view
                                   href="<?= Html::encode($detailUrl($item)) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Case order pages">
                <?php for ($n = 1; $n <= $pages; $n++): ?>
                    <?php if ($n === $page): ?>
                        <span class="pager__nav" aria-current="page"><?= $n ?></span>
                    <?php else: ?>
                        <a class="pager__nav" href="<?= Html::encode($linkWith(['page' => (string) $n])) ?>"><?= $n ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Synchronisation history</h2>

    <?php
    // Several runs may share a date, which is the whole point of the run-keyed design. Each is its
    // own row, with the counts the run actually recorded — never a success reported for an import
    // that failed locally.
?>
    <?php if ($runs === []): ?>
        <p class="util-muted">No synchronisation has been recorded yet.</p>
    <?php else: ?>
        <div class="o58-table-scroll">
        <table class="table o58-co-history">
            <thead>
                <tr>
                    <th scope="col">Run</th>
                    <th scope="col">Trigger</th>
                    <th scope="col">Started</th>
                    <th scope="col">Completed</th>
                    <th scope="col">Status</th>
                    <th scope="col">Received</th>
                    <th scope="col">New</th>
                    <th scope="col">Updated</th>
                    <th scope="col">Unchanged</th>
                    <th scope="col">Items</th>
                    <th scope="col">Failed</th>
                    <th scope="col">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <td>#<?= Html::encode((string) $run->id) ?></td>
                        <td><?= Html::encode($run->triggerSource) ?></td>
                        <td><?= $when($run->startedAt) ?></td>
                        <td><?= $when($run->completedAt) ?></td>
                        <td>
                            <span class="badge badge--<?= Html::encode($run->status->modifier()) ?>">
                                <?= Html::encode($run->status->label()) ?>
                            </span>
                        </td>
                        <td><?= Html::encode((string) $run->ordersReceived) ?></td>
                        <td><?= Html::encode((string) $run->ordersInserted) ?></td>
                        <td><?= Html::encode((string) $run->ordersUpdated) ?></td>
                        <td><?= Html::encode((string) $run->ordersUnchanged) ?></td>
                        <td><?= Html::encode((string) $run->itemsImported) ?></td>
                        <td><?= Html::encode((string) $run->failures) ?></td>
                        <td class="o58-co-note"><?= $dash($run->errorSummary) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="field__hint">
            Showing the most recent <?= Html::encode((string) count($runs)) ?> of
            <?= Html::encode((string) $runCount) ?> run(s).
        </p>
    <?php endif; ?>
</section>

<?php
/*
 * One dialog for the whole table, filled on demand.
 *
 * Rendering fifty orders' details into fifty hidden dialogs would make every page load carry the
 * detail of rows nobody opens; this holds nothing until a View is pressed, and the server sends only
 * the order that was asked for. It stays empty and unreachable when the script does not run, which is
 * why the link below it still points at a real page.
 */
?>
<dialog class="a2t-confirm o58-co-detail-dialog" data-o58-co-dialog
        aria-labelledby="o58-co-detail-title">
    <div class="o58-co-detail-dialog__head">
        <h2 class="a2t-confirm__title" id="o58-co-detail-title" data-o58-co-dialog-title>Case order</h2>
        <form method="dialog">
            <button class="btn btn--sm btn--secondary" type="submit">Close</button>
        </form>
    </div>
    <div class="o58-co-detail-dialog__body" data-o58-co-dialog-body tabindex="-1"></div>
</dialog>
