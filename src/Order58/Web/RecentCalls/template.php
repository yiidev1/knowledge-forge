<?php

declare(strict_types=1);

use App\Integration\Order58Recording\FixtureAvailability;
use App\Order58\Web\RecentCalls\RecentCallsAsset;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Order58 Recent Calls: a store, a Load Calls button, and a table the script fills.
 *
 * Nothing here is fetched while the page renders. The table body starts with one line of guidance; the
 * script asks {@see \App\Order58\Web\RecentCalls\ListAction} for the calls and writes every value with
 * `textContent`, and every address it uses arrives already built in that answer.
 *
 * @var Yiisoft\View\WebView $this
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var UrlGeneratorInterface $urlGenerator
 * @var array<int, string> $stores account id => store name
 * @var int $selectedStore
 * @var int $limit
 * @var array<string, string> $channelColumns channel => column heading, in table order
 * @var bool $useFixtures
 */

$assetManager->register(RecentCallsAsset::class);

$this->setTitle('Recent calls');
$this->setParameter('breadcrumbs', [
    ['label' => 'Order58 Data Management', 'route' => 'order58.index'],
    ['label' => 'Recent calls'],
]);

$listQuery = $useFixtures ? [FixtureAvailability::QUERY_PARAMETER => FixtureAvailability::FIXTURE] : [];
$listUrl = $urlGenerator->generate('order58.recent-calls.list', [], $listQuery);
$columnCount = 4 + count($channelColumns);
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Recent calls</h1>
        <p class="page-header__subtitle">
            Choose a store and load its latest <?= $limit ?> calls. Each call's mix, customer and agent
            recordings play or download on demand &mdash; nothing is fetched until you press a button.
        </p>
    </div>
</div>

<?php if ($useFixtures): ?>
    <div class="alert alert--warning" role="status">
        <p><?= Html::encode(FixtureAvailability::describe(true)) ?></p>
    </div>
<?php endif; ?>

<div class="card" data-rc data-rc-list="<?= Html::encode($listUrl) ?>">
    <form class="rc-toolbar" data-rc-form>
        <div class="rc-toolbar__field">
            <label class="field__label" for="rc-store">Store</label>
            <select class="field__control rc-toolbar__select" id="rc-store" name="store" data-rc-store>
                <?php foreach ($stores as $accountId => $name): ?>
                    <option value="<?= $accountId ?>"<?= $accountId === $selectedStore ? ' selected' : '' ?>>
                        <?= Html::encode($name) ?> (<?= $accountId ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn--primary" type="submit" data-rc-load>Load Calls</button>
    </form>

    <p class="rc-status" role="status" aria-live="polite" data-rc-status></p>

    <div class="table-wrap rc-table-wrap">
        <table class="table rc-table">
            <colgroup>
                <col class="rc-col-order">
                <col class="rc-col-session">
                <col class="rc-col-time">
                <?php foreach ($channelColumns as $channel => $heading): ?>
                    <col class="rc-col-audio">
                <?php endforeach; ?>
                <col class="rc-col-receipt">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Order No</th>
                    <th scope="col">Call Session ID</th>
                    <th scope="col">Call Time</th>
                    <?php foreach ($channelColumns as $channel => $heading): ?>
                        <th scope="col" data-rc-channel="<?= Html::encode($channel) ?>"><?= Html::encode($heading) ?></th>
                    <?php endforeach; ?>
                    <th scope="col">View Receipt</th>
                </tr>
            </thead>
            <tbody data-rc-body>
                <tr class="rc-placeholder">
                    <td colspan="<?= $columnCount ?>" class="util-muted">
                        Choose a store and press <strong>Load Calls</strong>.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <noscript>
        <p class="alert alert--warning">This page needs JavaScript to load calls and play recordings.</p>
    </noscript>
</div>
