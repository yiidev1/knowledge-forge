<?php

declare(strict_types=1);

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryPage;
use App\Order58\Domain\CallImportHistoryRow;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Application\Time\AppTimeZone;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var CallImportHistoryPage $result
 * @var int $page
 * @var list<RecordingChannel> $channels
 * @var AppTimeZone $appTimeZone
 */

$this->setTitle('Sync History');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Manage Order58 Calls', 'route' => 'order58.calls'],
    ['label' => 'Sync History'],
]);

$callsUrl = $urlGenerator->generate('order58.calls');
$historyUrl = $urlGenerator->generate('order58.calls.history');

/**
 * A channel cell, drawn exactly as the calls page draws it.
 *
 * Deliberately the same rules rather than a shared partial: it is four lines, and the two pages must not
 * be able to disagree about what a status looks like. An absent channel is a dash, which is not the same
 * statement as "Not available" — that one means the provider was asked and said no.
 */
$channelCell = static function (CallImportHistoryRow $row, RecordingChannel $channel): string {
    $item = $row->channel($channel);

    if ($item === null) {
        return '<td class="util-muted">—</td>';
    }

    $cell = '<td><span class="badge badge--' . Html::encode($item->status->badge()) . '">'
        . Html::encode($item->status->label()) . '</span>';

    if ($item->errorMessage !== null && $item->errorMessage !== '') {
        $cell .= '<div class="field__hint" title="' . Html::encode($item->errorMessage) . '">'
            . Html::encode(mb_strimwidth($item->errorMessage, 0, 60, '…')) . '</div>';
    }

    if ($item->status === Order58ImportStatus::TooLarge && $item->bytes !== null) {
        $cell .= '<div class="field__hint">' . Html::encode(number_format($item->bytes / 1048576, 1) . ' MB')
            . '</div>';
    }

    return $cell . '</td>';
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Sync History</h1>
        <p class="page-header__subtitle">
            View Order58 sync history across all stores.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn btn--secondary" href="<?= Html::encode($callsUrl) ?>">← Back to Manage Order58 Calls</a>
    </div>
</div>

<section class="card">
    <h2 class="card__title">
        All stores
        <?php if ($result->total > 0): ?>
            <span class="field__hint"><?= Html::encode(number_format($result->total)) ?> call(s)</span>
        <?php endif; ?>
    </h2>

    <?php if ($result->items === []): ?>
        <div class="empty">
            <div class="empty__title">Nothing imported yet</div>
            <p class="field__hint">
                Calls synced from Manage Order58 Calls appear here, for every store, newest first.
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Store</th>
                    <th>Call</th>
                    <th>Order</th>
                    <th>Call time</th>
                    <?php foreach ($channels as $channel): ?>
                        <th><?= Html::encode($channel->label()) ?></th>
                    <?php endforeach; ?>
                    <th>Overall</th>
                    <th>Provider</th>
                    <th>AI audio</th>
                    <th>Updated</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result->items as $row): ?>
                    <?php $outcome = $row->outcome(); ?>
                    <tr>
                        <td>
                            <?= Html::encode($row->storeName !== '' ? $row->storeName : 'Store') ?>
                            <div class="field__hint">#<?= Html::encode((string) $row->storeSourceId) ?></div>
                        </td>
                        <td><code><?= Html::encode($row->callSessionId) ?></code></td>
                        <td>
                            <?= $row->orderId === null
                                ? '<span class="util-muted">—</span>'
                                : Html::encode($row->orderId) ?>
                        </td>
                        <td><?= Html::encode($row->callTimeRaw) ?></td>
                        <?php foreach ($channels as $channel): ?>
                            <?= $channelCell($row, $channel) ?>
                        <?php endforeach; ?>
                        <td>
                            <span class="badge badge--<?= Html::encode($outcome->badge()) ?>">
                                <?= Html::encode($outcome->label()) ?>
                            </span>
                        </td>
                        <td><?= Html::encode($row->provider) ?></td>
                        <td><?= $row->generateAiAudio ? 'Yes' : 'No' ?></td>
                        <td class="util-muted"><?= Html::encode($appTimeZone->format($row->updatedAt)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        // Retry is not offered here, on purpose. It posts a store alongside the import id and redirects
        // back to that store's calls page, so a button on an all-stores list would take the operator
        // somewhere they did not ask to go. A failed call is retried from its own store's page.
        ?>
        <?= $this->render(dirname(__DIR__, 3) . '/Web/Shared/_partial/pager', [
            'page' => $page,
            'pageCount' => $result->pageCount(),
            'pageUrl' => static fn(int $p): string => $historyUrl . '?page=' . $p,
        ]) ?>

        <p class="field__hint">
            Recordings import in the background. Reload to see progress; finished recordings also appear
            on each store's own Audio to Text page.
        </p>
    <?php endif; ?>
</section>
