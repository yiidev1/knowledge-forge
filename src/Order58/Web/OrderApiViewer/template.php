<?php

declare(strict_types=1);

use App\Order58\Web\OrderApiViewer\FeedResult;
use App\Order58\Web\OrderApiViewer\OrderApiViewerAsset;
use App\Order58\Web\OrderApiViewer\OrderRow;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Case-order viewer.
 *
 * Renders six fields and a Play button. The six were chosen on the server — see {@see OrderRow} — so
 * there is no wider record in scope here to leak by accident, and no provider URL: a Play button
 * carries a session id and a date, and the address is rebuilt by the audio endpoint from constants.
 *
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var FeedResult $result
 * @var list<OrderRow> $rows
 * @var int $perPage
 */

// Paging only. Without it every row is visible at once, which is correct, just longer.
$assetManager->register(OrderApiViewerAsset::class);

$this->setTitle('Order API viewer');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Order API viewer'],
]);

$pageUrl = $urlGenerator->generate('order58.order-api-viewer');
$audioUrl = $urlGenerator->generate('order58.order-api-viewer.audio');
?>

<div class="page-header">
    <h1 class="page-header__title">Order API viewer</h1>
    <p class="page-header__subtitle">
        Live, read-only view of the case-order endpoint, with the call recording for each order.
        Nothing on this page is saved: no order, no recording, no database row, no file.
    </p>
</div>

<?php if (!$result->succeeded()): ?>
    <div class="alert alert--error">
        <strong>The order API could not be read.</strong>
        <p><?= Html::encode((string) $result->error) ?></p>
    </div>
<?php endif; ?>

<div class="alert alert--warning">
    <strong>Each load of this page is one request to a rationed endpoint.</strong>
    The provider limits this API, and the daily Case Order synchronisation spends from the same
    allowance. Nothing here polls or refreshes on its own, and playing audio costs no extra request —
    but please do not sit on Refresh.
</div>

<div class="card">
    <h2 class="card__title">This load</h2>

    <?php if ($result->succeeded()): ?>
        <dl class="a2t-meta">
            <div><dt>HTTP status</dt><dd class="util-mono"><?= Html::encode((string) $result->httpStatus) ?></dd></div>
            <div><dt>Orders received</dt><dd class="util-mono"><?= Html::encode((string) $result->ordersReceived) ?></dd></div>
            <div><dt>With a recording</dt><dd class="util-mono"><?= Html::encode((string) $result->playableCount()) ?></dd></div>
            <div><dt>Response size</dt><dd class="util-mono"><?= Html::encode(number_format((int) $result->responseBytes)) ?> bytes</dd></div>
        </dl>
    <?php endif; ?>

    <p>
        <a class="btn btn--primary" href="<?= Html::encode($pageUrl) ?>">Refresh (calls the API again)</a>
    </p>
</div>

<div class="card">
    <h2 class="card__title">Orders</h2>

    <?php if ($rows === []): ?>
        <p class="util-muted">No orders to show.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" data-order-api-viewer-table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Sequence</th>
                        <th>Account ID</th>
                        <th>Total Cost</th>
                        <th>Call Session ID</th>
                        <th>Call Recording</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="util-mono"><?= Html::encode($row->id === '' ? '—' : $row->id) ?></td>
                            <td class="util-mono"><?= Html::encode($row->seq === '' ? '—' : $row->seq) ?></td>
                            <td class="util-mono"><?= Html::encode($row->accountId === '' ? '—' : $row->accountId) ?></td>
                            <td class="util-mono"><?= Html::encode($row->totalCost === '' ? '—' : $row->totalCost) ?></td>
                            <td class="util-mono"><?= Html::encode($row->sessionId === '' ? '—' : $row->sessionId) ?></td>
                            <td>
                                <?php if (!$row->hasRecordings()): ?>
                                    <span class="util-muted">No Recording</span>
                                <?php else: ?>
                                    <?php foreach ($row->recordings as $i => $ref): ?>
                                        <?php
                                        $query = ['session' => $ref->sessionId, 'time' => $ref->time];
                                        $play = $audioUrl . '?' . http_build_query($query);
                                        $get = $audioUrl . '?' . http_build_query($query + ['download' => '1']);
                                        ?>
                                        <div class="a2t-player">
                                            <?php if (count($row->recordings) > 1): ?>
                                                <span class="util-muted">#<?= (int) $i + 1 ?></span>
                                            <?php endif; ?>
                                            <?php
                                            // preload="none" is what keeps a 200-row table from fetching
                                            // 200 recordings: nothing is requested until Play is pressed.
                                            ?>
                                            <audio class="a2t-player__control" controls preload="none"
                                                   src="<?= Html::encode($play) ?>"></audio>
                                            <a class="btn btn--secondary btn--sm" href="<?= Html::encode($get) ?>">Download</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        // Hidden until the script confirms there is more than one page. Without JavaScript the whole
        // table is simply visible, so a dead pager must not be shown next to it.
        ?>
        <div data-order-api-viewer-pager hidden>
            <p class="util-muted">
                <span data-pager-label></span>
                — <?= (int) $result->rowCount() ?> orders from this one response, shown
                <?= (int) $perPage ?> at a time. <strong>Paging does not call the API again.</strong>
            </p>
            <p class="table__actions">
                <button class="btn btn--secondary btn--sm" type="button" data-pager-prev>Previous</button>
                <button class="btn btn--secondary btn--sm" type="button" data-pager-next>Next</button>
            </p>
        </div>
    <?php endif; ?>
</div>
