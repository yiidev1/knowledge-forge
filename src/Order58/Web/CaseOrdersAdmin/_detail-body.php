<?php

declare(strict_types=1);

use App\Order58\Domain\Items\Order58OrderItem;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;

/**
 * One case order's facts, with no page chrome around them.
 *
 * Rendered in two places and therefore written for both: the standalone detail page wraps it in a
 * header and the admin layout, and the list page's View dialog injects it on its own. That is why it
 * declares its own helpers and names no URL — a fragment that linked "Back to Case Orders" would put
 * a navigation control inside a modal that has nothing to go back from.
 *
 * @var Yiisoft\View\WebView $this
 * @var array<string, mixed> $order
 * @var list<Order58OrderItem> $items
 * @var list<array<string, mixed>> $recordings
 * @var int $demoCount
 * @var AppTimeZone $appTimeZone
 * @var Closure(?string): array $decode
 */

$dash = static fn(mixed $v): string => $v === null || $v === ''
    ? '<span class="util-muted">&mdash;</span>'
    : Html::encode((string) $v);

$row = static fn(string $k, string $v): string
    => '<div class="a2t-confirm__row"><span class="a2t-confirm__key">' . Html::encode($k)
    . '</span><span class="a2t-confirm__value">' . $v . '</span></div>';

$orderedAt = is_numeric($order['source_created_at'] ?? null) && (int) $order['source_created_at'] > 0
    ? $appTimeZone->format(new DateTimeImmutable('@' . (int) $order['source_created_at']), 'M j, Y g:i A')
    : null;
?>
<section class="card">
    <h2 class="card__title">Order</h2>
    <?= $row('Seq', $dash($order['seq'] ?? null)) ?>
    <?= $row('Status', $dash($order['status'] ?? null)) ?>
    <?= $row('Type', $dash($order['type'] ?? null)) ?>
    <?= $row('Payment', $dash($order['payment_method'] ?? null)) ?>
    <?= $row('Total', $dash($order['total_amount'] ?? null)) ?>
    <?= $row('Discount', $dash($order['discount'] ?? null)) ?>
    <?= $row('Tax', $dash($order['tax'] ?? null)) ?>
    <?= $row('Tip', $dash($order['tip'] ?? null)) ?>
    <?= $row('Ordered', $dash($orderedAt)) ?>
    <?php
    // The phone is the delivery contact, read out of the document before redaction. The billing
    // address and every card field were stripped on the way in and are not stored at all.
?>
    <?= $row('Reservation phone', $dash($order['reservation_phone'] ?? null)) ?>
</section>

<section class="card">
    <h2 class="card__title">Items (<?= Html::encode((string) count($items)) ?>)</h2>

    <?php if ($items === []): ?>
        <p class="util-muted">
            No line items are stored for this order
            (<?= Html::encode((string) ($order['items_status'] ?? 'PENDING')) ?>).
        </p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Product</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Price</th>
                    <th scope="col">Modifiers, additionals and combinations</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                /*
                 * The canonical shape, not the raw `data` string.
                 *
                 * `comparison_json` is what a trainee's entry is actually scored against, so this
                 * is the view that matches the comparison. Reading the raw document here would
                 * show something the scoring never looks at.
                 */
                $canonical = $decode($item->comparisonJson);
                    ?>
                    <tr>
                        <td><?= Html::encode((string) ($item->position + 1)) ?></td>
                        <td>
                            <strong><?= Html::encode($item->name) ?></strong>
                            <?php if (($canonical['productName'] ?? null) !== null
                                && $canonical['productName'] !== $item->name): ?>
                                <div class="util-muted"><?= Html::encode((string) $canonical['productName']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= Html::encode($item->qty) ?></td>
                        <td><?= $dash($item->price) ?></td>
                        <td>
                            <?php
                            $parts = [];

                    foreach ((array) ($canonical['additionals'] ?? []) as $extra) {
                        if (is_array($extra) && isset($extra['name'])) {
                            $parts[] = '+ ' . (string) $extra['name'];
                        }
                    }

                    foreach ((array) ($canonical['combinations'] ?? []) as $combo) {
                        if (is_array($combo) && isset($combo['option'])) {
                            $parts[] = (string) ($combo['attribute'] ?? '') . ': '
                                . (string) $combo['option'];
                        }
                    }
                    ?>
                            <?= $parts === []
                        ? '<span class="util-muted">&mdash;</span>'
                        : Html::encode(implode(' · ', $parts)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2 class="card__title">Linked data</h2>
    <?= $row('Recordings', $recordings === []
        ? '<span class="util-muted">&mdash;</span>'
        : Html::encode((string) count($recordings)) . ' recording(s)') ?>
    <?= $row('Demo orders', $demoCount === 0
        ? '<span class="util-muted">&mdash;</span>'
        : Html::encode((string) $demoCount)) ?>
</section>

<section class="card">
    <h2 class="card__title">Synchronisation</h2>
    <?= $row('Item status', $dash($order['items_status'] ?? null)) ?>
    <?= $row('Items last synced', $dash(
        is_string($order['items_synced_at'] ?? null) ? $order['items_synced_at'] : null,
    )) ?>
    <?= $row('Order last synced', $dash(is_string($order['synced_at'] ?? null) ? $order['synced_at'] : null)) ?>
    <?= $row('First imported', $dash(is_string($order['created_at'] ?? null) ? $order['created_at'] : null)) ?>
    <?php // The change signal, shown so two copies of an order can be compared without opening them.?>
    <?= $row('Content hash', $dash($order['content_hash'] ?? null)) ?>
</section>
