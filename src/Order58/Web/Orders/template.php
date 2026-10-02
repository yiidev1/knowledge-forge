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
