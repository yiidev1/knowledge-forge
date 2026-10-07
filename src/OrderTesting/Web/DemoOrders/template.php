<?php

declare(strict_types=1);

use App\OrderTesting\Web\DemoOrdersAsset;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\TestAttempt;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var int $sourceId
 * @var string $storeName
 * @var int $sourceOrderId
 * @var list<DemoOrder> $orders
 * @var array<int, TestAttempt> $attempts keyed by attempt row id
 * @var bool $sourceOrderIsMirrored
 */

$assetManager->register(DemoOrdersAsset::class);

$this->setTitle('Demo orders for #' . $sourceOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => 'Order Testing', 'route' => 'order-testing'],
    ['label' => $storeName, 'route' => 'order-testing.store', 'arguments' => ['sourceId' => $sourceId]],
    ['label' => 'Demo orders'],
]);

$storeUrl = $urlGenerator->generate('order-testing.store', ['sourceId' => $sourceId]);

/**
 * Who created a demo order, or an honest blank.
 *
 * Attribution is only ever shown when a demo order is linked to a real attempt row. Everything else —
 * an order imported with nothing open, an order created from Order58's own screens, an order whose
 * attempt has since been deleted — reads "Unmatched", because an invented name on a training record is
 * worse than no name at all.
 */
$testedBy = static function (DemoOrder $order) use ($attempts): string {
    $attempt = $order->matchedAttemptId === null ? null : ($attempts[$order->matchedAttemptId] ?? null);

    if ($attempt === null) {
        return '<span class="util-muted" title="No test attempt was open for this source order when '
            . 'this demo order was imported.">Unmatched</span>';
    }

    $name = $attempt->initiatedByName ?? ('#' . $attempt->initiatedById);

    return Html::encode($attempt->initiatedByType->label()) . ' ' . Html::encode($name);
};

$money = static fn(?string $value): string => $value === null
    ? '<span class="util-muted">—</span>'
    : '$' . Html::encode($value);
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Demo orders for #<?= Html::encode((string) $sourceOrderId) ?></h1>
        <p class="page-header__subtitle">
            Orders a trainee recreated in Order58 from this call.
            Each one is compared against the original on its own.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= Html::encode($storeUrl) ?>">Back to <?= Html::encode($storeName) ?></a>
    </div>
</div>

<?php if (!$sourceOrderIsMirrored): ?>
    <?php // Said once here rather than on every row: it is a property of the source order, not of any?>
    <?php // one demo order, and each comparison repeats it in place anyway.?>
    <div class="notice notice--warning">
        Order #<?= Html::encode((string) $sourceOrderId) ?> is not in the local Order58 mirror, so there
        is nothing to compare these against yet. The demo orders below are still complete. Syncing this
        store for the date the order was placed will fill the other side in.
    </div>
<?php endif; ?>

<?php if ($orders === []): ?>
    <div class="empty" style="padding: 2rem;">
        <div class="empty__icon" aria-hidden="true">🧪</div>
        <div class="empty__title">No demo orders yet</div>
        <p>
            Nothing has been imported for this order. A demo order appears here shortly after a trainee
            submits one in Order58.
        </p>
    </div>
<?php else: ?>
    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Demo order</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Tested by</th>
                    <th>Imported</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <?php $compareUrl = $urlGenerator->generate('order-testing.demo-compare', [
                    'sourceId' => $sourceId,
                    'sourceOrderId' => $sourceOrderId,
                    'demoOrderId' => $order->demoOrderId,
                ]); ?>
                <tr>
                    <td><code>#<?= Html::encode((string) $order->demoOrderId) ?></code></td>
                    <td>
                        <?= Html::encode($order->customerName() ?? '—') ?>
                        <?php if ($order->customerPhone !== null): ?>
                            <div class="field__hint"><?= Html::encode($order->customerPhone) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= Html::encode($order->orderType ?? '—') ?></td>
                    <td><?= Html::encode($order->paymentMethod ?? '—') ?></td>
                    <td><?= $money($order->totalAmount) ?></td>
                    <td><?= $testedBy($order) ?></td>
                    <td class="util-muted"><?= Html::encode($order->sourceFilename) ?></td>
                    <td><a class="btn btn--secondary" href="<?= Html::encode($compareUrl) ?>">Compare</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
