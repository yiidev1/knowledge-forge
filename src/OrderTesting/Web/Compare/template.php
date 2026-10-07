<?php

declare(strict_types=1);

use App\OrderTesting\Web\DemoOrdersAsset;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\OrderComparison;
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
 * @var DemoOrder $demo
 * @var TestAttempt|null $attempt
 * @var OrderComparison $comparison
 */

$assetManager->register(DemoOrdersAsset::class);

$this->setTitle('Compare #' . $demo->sourceOrderId . ' with demo #' . $demo->demoOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => 'Order Testing', 'route' => 'order-testing'],
    ['label' => $storeName, 'route' => 'order-testing.store', 'arguments' => ['sourceId' => $sourceId]],
    [
        'label' => 'Demo orders',
        'route' => 'order-testing.demo-orders',
        'arguments' => ['sourceId' => $sourceId, 'sourceOrderId' => $demo->sourceOrderId],
    ],
    ['label' => 'Demo #' . $demo->demoOrderId],
]);

$listUrl = $urlGenerator->generate('order-testing.demo-orders', [
    'sourceId' => $sourceId,
    'sourceOrderId' => $demo->sourceOrderId,
]);

/** A value as the document recorded it, or an explicit blank. Never guessed at, never reformatted. */
$value = static fn(?string $v): string => $v === null || $v === ''
    ? '<span class="util-muted">—</span>'
    : Html::encode($v);

/**
 * The verdict chip.
 *
 * The word comes from the enum and the colour from its own modifier, so this template holds no opinion
 * about which differences matter — that question belongs to scoring, which is not built yet.
 */
$chip = static fn(FieldComparison|ItemComparison $c): string => '<span class="ot-verdict ot-verdict--'
    . Html::encode($c->status->modifier()) . '">' . Html::encode($c->status->label()) . '</span>';

$itemSummary = static function (?NormalizedItem $item) use ($value): string {
    if ($item === null) {
        return '<span class="util-muted">Not present</span>';
    }

    $out = '<strong>' . Html::encode($item->name ?? 'Unnamed item') . '</strong>';

    if ($item->sn !== null) {
        $out .= ' <code>' . Html::encode($item->sn) . '</code>';
    }

    return $out;
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">
            Source #<?= Html::encode((string) $demo->sourceOrderId) ?>
            vs demo #<?= Html::encode((string) $demo->demoOrderId) ?>
        </h1>
        <p class="page-header__subtitle">
            What the call actually ordered, beside what the trainee entered in Order58.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= Html::encode($listUrl) ?>">All demo orders for this call</a>
    </div>
</div>

<div class="card">
    <dl class="ot-summary">
        <div><dt>Store</dt><dd><?= Html::encode($storeName) ?> &middot; Store #<?= Html::encode((string) $sourceId) ?></dd></div>
        <div><dt>Source order</dt><dd>#<?= Html::encode((string) $demo->sourceOrderId) ?></dd></div>
        <div><dt>Demo order</dt><dd>#<?= Html::encode((string) $demo->demoOrderId) ?></dd></div>
        <div>
            <dt>Tested by</dt>
            <dd>
                <?php if ($attempt === null): ?>
                    <?php // Never invented. See the import rules: attribution is written once, when a?>
                    <?php // demo order first arrives, and only if an attempt was open for it.?>
                    <span class="util-muted">Unknown &mdash; no matched attempt</span>
                <?php else: ?>
                    <?= Html::encode($attempt->initiatedByType->label()) ?>
                    <?= Html::encode($attempt->initiatedByName ?? ('#' . $attempt->initiatedById)) ?>
                    <span class="util-muted">(<?= Html::encode($attempt->status->label()) ?>)</span>
                <?php endif; ?>
            </dd>
        </div>
        <div><dt>Imported from</dt><dd><code><?= Html::encode($demo->sourceFilename) ?></code></dd></div>
    </dl>
</div>

<?php if (!$comparison->hasOriginal()): ?>
    <div class="notice notice--warning">
        <strong>Source order unavailable in the local mirror.</strong>
        Order #<?= Html::encode((string) $demo->sourceOrderId) ?> has not been synced for this store, so
        there is nothing to compare against. Everything the trainee entered is shown below. Syncing the
        store for the date this order was placed will fill the left-hand column in; nothing here is lost
        in the meantime.
    </div>
<?php endif; ?>

<?php foreach ($comparison->sections as $section): ?>
    <?php /** @var ComparisonSection $section */ ?>
    <div class="card">
        <h2 class="card__title"><?= Html::encode($section->title) ?></h2>
        <table class="table ot-compare">
            <thead>
                <tr>
                    <th scope="col">Field</th>
                    <th scope="col">Original order</th>
                    <th scope="col">Demo / test order</th>
                    <th scope="col">Result</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($section->fields as $field): ?>
                <tr>
                    <th scope="row"><?= Html::encode($field->label) ?></th>
                    <td><?= $value($field->original) ?></td>
                    <td><?= $value($field->demo) ?></td>
                    <td><?= $chip($field) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<div class="card">
    <h2 class="card__title">Order items</h2>
    <?php if ($comparison->items === []): ?>
        <p class="util-muted">Neither order records any line items.</p>
    <?php else: ?>
        <?php // Paired by product name, never by a document id: a demo order's item ids are synthetic?>
        <?php // and pairing on one would compare a trainee's line against an unrelated original.?>
        <table class="table ot-compare">
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Original order</th>
                    <th scope="col">Demo / test order</th>
                    <th scope="col">Result</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($comparison->items as $item): ?>
                <tr class="ot-compare__item-head">
                    <th scope="row"><?= Html::encode($item->label()) ?></th>
                    <td><?= $itemSummary($item->original) ?></td>
                    <td><?= $itemSummary($item->demo) ?></td>
                    <td><?= $chip($item) ?></td>
                </tr>
                <?php foreach ($item->fields as $field): ?>
                    <tr class="ot-compare__item-field">
                        <th scope="row"><?= Html::encode($field->label) ?></th>
                        <td><?= $value($field->original) ?></td>
                        <td><?= $value($field->demo) ?></td>
                        <td><?= $chip($field) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<p class="util-muted">
    These are differences, not a score. No accuracy percentage, weighting or pass mark is calculated
    here; a later scoring pass reads the same comparison this page renders.
</p>
