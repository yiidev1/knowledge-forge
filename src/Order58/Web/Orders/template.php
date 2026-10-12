<?php

declare(strict_types=1);

use App\Order58\Domain\Orders\OrderListQuery;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * Order58 Orders: synchronise a store's orders for a date range, then read what is stored.
 *
 * ## The phone is shown in full
 *
 * `reservation_phone` is a customer's number, and this table prints it. That is deliberate — an operator
 * following up an order needs the number they can actually ring — and it is why the page sends
 * `Cache-Control: no-store, private` and sits behind the admin gate. It is still never logged, and it
 * reaches no response other than this one.
 *
 * ## No raw payload on this page
 *
 * `payload_json` holds card metadata, addresses and the customer's own words. The list prints none of
 * it; a detail view that renders it safely is a separate, deliberate piece of work.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Csrf $csrf
 * @var array<int, string> $storeOptions
 * @var list<array<array-key, mixed>> $rows
 * @var int $total
 * @var OrderListQuery $listQuery
 * @var \App\Order58\Domain\Items\ItemSyncSummary $itemSyncSummary item progress for the store filter
 * @var int|null $syncBatchId the operation this page was redirected from, if any
 * @var string $syncStatusUrl where the panel polls for that operation's progress
 * @var int $perPage
 * @var int $maxDates
 * @var int|null $formAccountId
 * @var string|null $formFrom
 * @var string|null $formTo
 */

$this->setTitle('Order58 orders');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Orders'],
]);

$pageUrl = $urlGenerator->generate('order58.orders');
$pages = (int) ceil($total / max(1, $perPage));
$currentPage = max(1, $listQuery->page);

/**
 * The stored number, in full.
 *
 * Shown rather than masked at the administrator's request: this page is already behind the admin gate,
 * the number is the one an operator rings to follow up an order, and the last four digits alone are not
 * enough to do that. It is escaped like every other cell and is still a customer's phone number, so it
 * stays out of logs and out of any response that is not this authenticated page.
 */
$phoneOf = static fn(?string $phone): string => $phone === null || $phone === '' ? '—' : $phone;

$money = static fn(mixed $value): string => $value === null ? '—' : number_format((float) $value, 2);

$sourceTime = static fn(mixed $ts): string => $ts === null || (int) $ts === 0
    ? '—'
    : gmdate('Y-m-d H:i', (int) $ts) . ' UTC';


/**
 * The ORDER ITEMS cell: what is known about this order's original line items, and a way to fetch them.
 *
 * Six states, and the two that look alike are kept apart deliberately. `EMPTY` means the provider
 * answered and this order genuinely has no lines — it is comparable. `FAILED` means we do not know,
 * and it never claims the order is empty. `PENDING` is "never considered", which is where every
 * historical order starts so that installing this feature queues nothing.
 *
 * The button is always offered, even mid-sync: pressing it while the worker holds the order is
 * answered with "already in progress" and costs no provider request, which is a better outcome than a
 * control that disappears exactly when somebody wants to know what is happening.
 */
/**
 * `items_synced_at`, as a stored UTC datetime, shortened for a table cell.
 *
 * The stored value is kept in the `title` so the exact timestamp is still available on hover and to a
 * screen reader — a date alone is not enough to decide whether a snapshot is worth re-fetching.
 */
$syncedAt = static fn(mixed $value): ?string => $value === null || (string) $value === ''
    ? null
    : (string) $value;

$itemsLabel = static function (array $row) use ($syncedAt): string {
    $status = (string) ($row['items_status'] ?? 'PENDING');
    $count = (int) ($row['items_count'] ?? 0);
    $stamp = $syncedAt($row['items_synced_at'] ?? null);
    $lines = $count . ' item' . ($count === 1 ? '' : 's');

    // `items_synced_at` is the ONLY thing separating "never successfully synced" from "a previous
    // snapshot is still stored". Both can sit under a FAILED or PROCESSING status, and they mean
    // completely different things to somebody deciding whether a comparison can be trusted.
    $kept = $stamp === null
        ? ''
        : ' <span class="util-muted" title="Last successful sync: ' . Html::encode($stamp)
            . ' UTC">' . $lines . ' from ' . Html::encode(substr($stamp, 0, 10)) . '</span>';

    return match ($status) {
        'QUEUED' => '<span class="badge badge--muted">Queued</span>',
        // A refresh in flight over a kept snapshot still has usable items behind it.
        'PROCESSING' => '<span class="badge badge--info">Syncing…</span>' . $kept,
        'SYNCED' => '<span class="badge badge--success">Synced</span> <span class="util-muted"'
            . ($stamp === null ? '' : ' title="Last successful sync: ' . Html::encode($stamp) . ' UTC"')
            . '>' . $lines . '</span>',
        // Authoritative, and NOT the same statement as "we could not find out".
        'EMPTY' => '<span class="badge badge--success">No items</span>',
        // Failed with a snapshot is a freshness warning; failed without one is an absence of data.
        'FAILED' => $stamp === null
            ? '<span class="badge badge--error">Failed</span>'
            : '<span class="badge badge--warning">Stale</span>' . $kept,
        default => '<span class="util-muted">Not synced</span>',
    };
};

$itemsCell = static function (array $row) use ($itemsLabel, $csrf, $urlGenerator, $listQuery): string {
    $action = $urlGenerator->generate('order58.order-items.sync');

    // Every filter the listing is currently showing, so a sync returns the operator to the page they
    // were reading rather than to an unfiltered page one.
    $carried = [
        'account_id' => $listQuery->accountId,
        'order_id' => $listQuery->sourceOrderId,
        'status' => $listQuery->status,
        'list_from' => $listQuery->dateFrom,
        'list_to' => $listQuery->dateTo,
        'page' => $listQuery->page > 1 ? $listQuery->page : null,
    ];

    $hidden = '';
    foreach ($carried as $name => $value) {
        if ($value !== null && $value !== '') {
            $hidden .= '<input type="hidden" name="' . Html::encode($name)
                . '" value="' . Html::encode((string) $value) . '">';
        }
    }

    return $itemsLabel($row)
        . '<form class="o58-items-form" method="post" action="' . Html::encode($action) . '">'
        . $csrf->hiddenInput()
        . '<input type="hidden" name="account_id" value="' . Html::encode((string) $row['account_id']) . '">'
        . '<input type="hidden" name="source_order_id" value="'
        . Html::encode((string) $row['source_order_id']) . '">'
        . '<input type="hidden" name="return" value="order58.orders">'
        . $hidden
        . '<button class="btn btn--sm" type="submit" data-busy-label="Syncing…">Sync Order Items</button>'
        . '</form>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Order58 orders</h1>
        <p class="page-header__subtitle">
            Mirror a store's orders for a date range, then read them here. Nothing on this page changes an
            order at Order58.
        </p>
    </div>
</div>

<section class="card">
    <h2 class="card__title">Synchronise orders</h2>

    <?php
// POST, because it writes. The sync happens in this request and the result is reported on the page it
// redirects to — there is no worker and nothing to wait for afterwards.
?>
    <form method="post" action="<?= Html::encode($urlGenerator->generate('order58.orders.sync')) ?>"
          class="store-picker" role="group" data-o58-orders-sync>
        <?= $csrf->hiddenInput() ?>

        <label class="util-visually-hidden" for="o58o-store">Store</label>
        <select class="field__control store-picker__select" id="o58o-store" name="account_id" required>
            <option value="">Choose a store…</option>
            <?php foreach ($storeOptions as $sourceId => $name): ?>
                <?php // The source id is in the label because store names repeat; picking the wrong row syncs the wrong account.?>
                <option value="<?= Html::encode((string) $sourceId) ?>"
                    <?= $formAccountId === $sourceId ? ' selected' : '' ?>>
                    <?= Html::encode($name . ' — #' . $sourceId) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="field__label store-picker__label" for="o58o-from">Start</label>
        <input class="field__control store-picker__date" type="date" id="o58o-from" name="date_from"
               value="<?= Html::encode((string) $formFrom) ?>" required>

        <label class="field__label store-picker__label" for="o58o-to">End</label>
        <input class="field__control store-picker__date" type="date" id="o58o-to" name="date_to"
               value="<?= Html::encode((string) $formTo) ?>" required>

        <button class="btn btn--primary" type="submit">Sync orders</button>
    </form>

    <p class="field__hint">
        At most <?= $maxDates ?> dates at a time. Use the same date twice to sync a single day.
        The orders are fetched and saved while you wait, so the page takes a moment to come back.
    </p>

    <?php if ($storeOptions === []): ?>
        <p class="field__hint">
            No active Order58 stores are mirrored yet. Run a store sync from Order58 Data Management first.
        </p>
    <?php endif; ?>
</section>

<?php
// THIS OPERATION's progress, which is a different question from the bar below it.
//
// The bar underneath answers "how much of this store's history has its items", and is unchanged. This
// panel answers "what is the sync I just started doing", and exists only after one. Keeping them apart
// is the point: a figure that sometimes meant one and sometimes the other would be worse than either.
//
// Rendered server-side from the batch id carried back by the redirect, then refreshed in place by
// polling, so it is correct with scripts off and live with them on.
?>
<?php if ($syncBatchId !== null): ?>
    <section class="card o58-sync-op"
             data-o58-sync-batch="<?= Html::encode((string) $syncBatchId) ?>"
             data-o58-sync-url="<?= Html::encode($syncStatusUrl) ?>">
        <div class="o58-sync-op__heading">
            <h2 class="card__title o58-sync-op__title">This synchronisation</h2>
            <span class="ot-verdict ot-verdict--info" data-o58-sync-state>Starting…</span>
        </div>

        <progress class="o58-sync-op__bar" data-o58-sync-bar value="0" max="100"></progress>

        <p class="o58-sync-op__detail">
            <span class="badge badge--muted" data-o58-sync-orders>Orders: —</span>
            <span class="badge badge--success" data-o58-sync-done hidden></span>
            <span class="badge badge--info" data-o58-sync-remaining hidden></span>
            <span class="badge badge--error" data-o58-sync-failed hidden></span>
            <span class="badge badge--muted" data-o58-sync-skipped hidden></span>
        </p>

        <p class="o58-sync-op__note" data-o58-sync-note hidden></p>

        <p class="o58-sync-op__scope">
            Item requests are made by the Order58 importer, not by this page. If nothing moves for a
            few minutes the importer is probably not running — its systemd units have to be installed
            and enabled for items to be fetched automatically.
        </p>
    </section>
<?php endif; ?>

<?php
// Original order items, across the orders this listing covers.
//
// One grouped count, computed in the action — see ItemSyncSummaryReaderInterface. It follows the STORE
// filter and nothing else: the date, order-id and status filters pick which rows appear in the table,
// while this answers how much of the store's history has its items. The scope is printed in words
// beside the numbers so the figure cannot be read as meaning something narrower.
//
// Rendered only when there are orders to report on; an empty bar reads as a fault rather than as an
// absence of work.
?>
<?php if (!$itemSyncSummary->isEmpty()): ?>
    <section class="card o58-item-sync">
        <div class="o58-item-sync__heading">
            <h2 class="card__title o58-item-sync__title">Original order items</h2>
            <span class="o58-item-sync__count">
                <?= $itemSyncSummary->ready ?> of <?= $itemSyncSummary->total ?> ready to compare
            </span>
        </div>

        <?php // A real <progress>: the browser draws it, announces it and honours reduced-motion.?>
        <progress class="o58-item-sync__bar"
                  value="<?= $itemSyncSummary->ready ?>"
                  max="<?= $itemSyncSummary->total ?>"></progress>

        <p class="o58-item-sync__detail">
            <?php if ($itemSyncSummary->notSynced > 0): ?>
                <span class="badge badge--muted"><?= $itemSyncSummary->notSynced ?> not synced</span>
            <?php endif; ?>
            <?php if ($itemSyncSummary->inProgress > 0): ?>
                <span class="badge badge--info"><?= $itemSyncSummary->inProgress ?> in progress</span>
            <?php endif; ?>
            <?php if ($itemSyncSummary->stale > 0): ?>
                <span class="badge badge--warning"><?= $itemSyncSummary->stale ?> from an earlier fetch</span>
            <?php endif; ?>
            <?php if ($itemSyncSummary->failed > 0): ?>
                <span class="badge badge--error"><?= $itemSyncSummary->failed ?> failed</span>
            <?php endif; ?>
            <?php if ($itemSyncSummary->outstanding() === 0 && !$itemSyncSummary->needsAttention()): ?>
                <span class="badge badge--success">Every order in this view has its items</span>
            <?php endif; ?>
        </p>

        <p class="o58-item-sync__scope">
            <?php if ($listQuery->accountId === null): ?>
                Counted across all <?= $itemSyncSummary->total ?> stored orders, every store.
            <?php else: ?>
                Counted across all <?= $itemSyncSummary->total ?> stored orders of the selected store.
            <?php endif; ?>
            Date and order filters below do not change this count. Items are fetched per order; nothing
            on this page contacts Order58 by itself.
        </p>
    </section>
<?php endif; ?>

<?php
// `o58-wide` is the marker `.content:has(.o58-wide)` looks for: it opens the page column past the
// standard 1240px so this ten-column table fits without each row wrapping. The same marker the Calls
// and Call Recordings pages already use, so all three widen alike.
?>
<section class="card o58-wide">
    <h2 class="card__title">Stored orders</h2>

    <?php // A GET form: a filtered list is a readable, shareable address.?>
    <form method="get" action="<?= Html::encode($pageUrl) ?>" class="store-picker o58-filters" role="group">
        <label class="util-visually-hidden" for="o58o-filter-store">Store</label>
        <select class="field__control store-picker__select" id="o58o-filter-store" name="account_id">
            <option value="">All stores</option>
            <?php foreach ($storeOptions as $sourceId => $name): ?>
                <option value="<?= Html::encode((string) $sourceId) ?>"
                    <?= $listQuery->accountId === $sourceId ? ' selected' : '' ?>>
                    <?= Html::encode($name . ' — #' . $sourceId) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="util-visually-hidden" for="o58o-order-id">Order ID</label>
        <?php // Sized explicitly: `.field__control` is `width: 100%`, which in this flex row would claim the whole line.?>
        <input class="field__control o58-filters__id" type="text" inputmode="numeric" id="o58o-order-id"
               name="order_id" placeholder="Order ID"
               value="<?= Html::encode((string) $listQuery->sourceOrderId) ?>">

        <?php
        // Hidden visually, not removed: a date input already shows its own format, and two inline
        // labels are what pushed this row past the width five controls have to share. Screen readers
        // and the `title` still name both fields.
?>
        <label class="util-visually-hidden" for="o58o-list-from">Placed from</label>
        <input class="field__control store-picker__date" type="date" id="o58o-list-from" name="list_from"
               title="Placed from" value="<?= Html::encode((string) $listQuery->dateFrom) ?>">

        <label class="util-visually-hidden" for="o58o-list-to">Placed to</label>
        <input class="field__control store-picker__date" type="date" id="o58o-list-to" name="list_to"
               title="Placed to" value="<?= Html::encode((string) $listQuery->dateTo) ?>">

        <button class="btn" type="submit">Filter</button>
    </form>

    <?php if ($rows === []): ?>
        <p class="field__hint">No orders match. Synchronise a store and date range above to mirror some.</p>
    <?php else: ?>
        <p class="field__hint"><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?> stored.</p>

        <div class="o58-table-scroll">
            <table class="table o58-orders-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Store</th>
                        <th>Phone</th>
                        <th>Type</th>
                        <th>Payment</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Test</th>
                        <th>Placed</th>
                        <th>Synced</th>
                        <?php // The original line items, and the control that fetches them now.?>
                        <th>Order Items</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $accountId = (int) $row['account_id']; ?>
                        <tr>
                            <td class="util-mono"><?= Html::encode((string) $row['source_order_id']) ?></td>
                            <td class="o58-cell-store"><?= Html::encode($storeOptions[$accountId] ?? ('#' . $accountId)) ?></td>
                            <td class="util-mono"><?= Html::encode($phoneOf(
                                $row['reservation_phone'] === null ? null : (string) $row['reservation_phone'],
                            )) ?></td>
                            <td><?= Html::encode((string) ($row['type'] ?? '—')) ?></td>
                            <td><?= Html::encode((string) ($row['payment_method'] ?? '—')) ?></td>
                            <td class="util-mono"><?= Html::encode($money($row['total_amount'] ?? null)) ?></td>
                            <td><?= Html::encode((string) ($row['status'] ?? '—')) ?></td>
                            <td><?= (int) $row['is_test'] === 1 ? 'Yes' : 'No' ?></td>
                            <td><?= Html::encode($sourceTime($row['source_created_at'] ?? null)) ?></td>
                            <td><?= Html::encode((string) $row['synced_at']) ?></td>
                            <td class="o58-items-cell"><?= $itemsCell($row) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <?php
            // The current filters travel with every page link, so paging never silently widens the list.
            $linkFor = static function (int $page) use ($urlGenerator, $listQuery): string {
                return $urlGenerator->generate('order58.orders', [], array_filter([
                    'account_id' => $listQuery->accountId,
                    'order_id' => $listQuery->sourceOrderId,
                    'list_from' => $listQuery->dateFrom,
                    'list_to' => $listQuery->dateTo,
                    'page' => $page,
                ], static fn(mixed $v): bool => $v !== null));
            };
            ?>
            <p class="field__hint">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= Html::encode($linkFor($currentPage - 1)) ?>">&larr; Previous</a>
                <?php endif; ?>
                Page <?= $currentPage ?> of <?= $pages ?>
                <?php if ($currentPage < $pages): ?>
                    <a href="<?= Html::encode($linkFor($currentPage + 1)) ?>">Next &rarr;</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>
