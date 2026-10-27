<?php

declare(strict_types=1);

use App\Order58\Domain\Items\Order58OrderItem;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;

/**
 * One case order, as its own page.
 *
 * The facts live in `_detail-body.php`, which the list page's View dialog renders too; this file is
 * the chrome around them — title, breadcrumbs, and the way back to the list. Keeping the page means a
 * View link still works with no script, and a detail URL stays something that can be shared.
 *
 * @var Yiisoft\View\WebView $this
 * @var array<string, mixed> $order
 * @var string|null $storeName
 * @var list<Order58OrderItem> $items
 * @var list<array<string, mixed>> $recordings
 * @var int $demoCount
 * @var AppTimeZone $appTimeZone
 * @var string $listUrl
 * @var Closure(?string): array $decode
 */

$this->setTitle('Case Order #' . (string) $order['source_order_id']);
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Case Orders', 'route' => 'order58.case-orders'],
    ['label' => '#' . (string) $order['source_order_id']],
]);

$dash = static fn(mixed $v): string => $v === null || $v === ''
    ? '<span class="util-muted">&mdash;</span>'
    : Html::encode((string) $v);

?>
<div class="page-header o58-wide">
    <div>
        <h1 class="page-header__title">Case Order #<?= Html::encode((string) $order['source_order_id']) ?></h1>
        <p class="page-header__subtitle">
            <?= $dash($storeName) ?> &middot; account <?= Html::encode((string) $order['account_id']) ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($listUrl) ?>">Back to Case Orders</a>
    </div>
</div>

<?= $this->render(__DIR__ . '/_detail-body', [
    'order' => $order,
    'items' => $items,
    'recordings' => $recordings,
    'demoCount' => $demoCount,
    'appTimeZone' => $appTimeZone,
    'decode' => $decode,
]) ?>
