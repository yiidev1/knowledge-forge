<?php

declare(strict_types=1);

use App\OrderTesting\Web\DemoOrdersAsset;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
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
 * @var Yiisoft\Yii\View\Renderer\Csrf $csrf
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

// Fetches the ORIGINAL order's items — the left-hand column — and nothing else. The demo order on the
// right is a file somebody's listener wrote and is never touched here; its id travels with the form
// only so that this exact comparison is what reloads afterwards.
//
// The literal route name, not a constant: this module may not name `App\Order58`, which owns the
// action. A name is not a namespace, so the module boundary holds.
$syncItemsUrl = $urlGenerator->generate('order58.order-items.sync');

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

/**
 * The item-synchronisation state, in words an administrator can act on.
 *
 * Named in the summary card and not only explained above the item table: somebody scanning this card
 * needs to know whether the comparison below can be trusted before they read any of it.
 *
 * `EMPTY` deliberately reads as "No items" rather than "Synced, 0 items": the provider confirmed the
 * order has no lines, which is a comparable fact, and "0 items" reads as a sync that quietly failed.
 */
$itemStateLabel = match ($comparison->itemAvailability) {
    ItemAvailability::Synced => 'Synced',
    ItemAvailability::AuthoritativeEmpty => 'No items',
    ItemAvailability::Queued => 'Queued',
    ItemAvailability::Fetching => 'Synchronizing',
    ItemAvailability::Unavailable => 'Failed',
    ItemAvailability::StaleAfterFailure => 'Stale — last refresh failed',
    ItemAvailability::StaleWhileFetching => 'Stale — refreshing',
    ItemAvailability::NeverRequested => 'Not synced',
};

$itemStateModifier = match ($comparison->itemAvailability) {
    ItemAvailability::Synced, ItemAvailability::AuthoritativeEmpty => 'match',
    ItemAvailability::Unavailable => 'different',
    ItemAvailability::StaleAfterFailure, ItemAvailability::StaleWhileFetching => 'extra',
    default => 'unknown',
};

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
        <form method="post" action="<?= Html::encode($syncItemsUrl) ?>">
            <?= $csrf->hiddenInput() ?>
            <input type="hidden" name="account_id" value="<?= Html::encode((string) $sourceId) ?>">
            <input type="hidden" name="source_order_id"
                   value="<?= Html::encode((string) $demo->sourceOrderId) ?>">
            <input type="hidden" name="demo_order_id"
                   value="<?= Html::encode((string) $demo->demoOrderId) ?>">
            <input type="hidden" name="return" value="order-testing.demo-compare">
            <button class="btn btn--secondary" type="submit"
                    data-busy-label="Syncing…">Sync Original Order Items</button>
        </form>
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
        <div>
            <dt>Original items</dt>
            <dd>
                <span class="ot-verdict ot-verdict--<?= Html::encode($itemStateModifier) ?>">
                    <?= Html::encode($itemStateLabel) ?>
                </span>
                <?php if ($comparison->itemsSyncedAt !== null): ?>
                    <span class="util-muted">
                        <?= $comparison->itemAvailability->isStale() ? 'from' : 'synced' ?>
                        <?= Html::encode($comparison->itemsSyncedAt) ?> UTC
                    </span>
                <?php endif; ?>
            </dd>
        </div>
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

    <?php if ($comparison->itemNote() !== ''): ?>
        <?php
        // Why the comparison below is what it is. Two different kinds of message share this one place:
        // "these cannot be compared" and "these are from an earlier fetch". Both are statements about
        // the synchronisation, and neither is a statement about the trainee — which is exactly what the
        // old behaviour got wrong when it reported every demo line as invented.
        ?>
        <div class="alert alert--<?= Html::encode($comparison->itemAvailability->modifier()) ?>"
             role="status">
            <?= Html::encode($comparison->itemNote()) ?>
            <?php if ($comparison->itemsSyncedAt !== null && $comparison->itemAvailability->isStale()): ?>
                Last synchronized <?= Html::encode($comparison->itemsSyncedAt) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($comparison->items === []): ?>
        <?php if ($comparison->itemsWereCompared()): ?>
            <?php // Both sides known, and both empty. That is agreement, not an absence of data.?>
            <p class="util-muted">Neither order records any line items.</p>
        <?php else: ?>
            <p class="util-muted">No line items to show.</p>
        <?php endif; ?>
    <?php else: ?>
        <?php
        // Paired on the merchant's catalogue id where both documents carry one, and on the
        // canonicalised product name otherwise — never on a document line id, which a demo document is
        // allowed to invent. Lines of the same product pair by closest match rather than by position.
        ?>
        <table class="table ot-compare ot-items">
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

    <?php if ($comparison->removedItems !== []): ?>
        <?php
        // Lines the provider marked removed. Listed apart and never compared: a trainee is right not to
        // have entered them, so counting them as "missing in demo" would penalise correct work.
        ?>
        <h3 class="card__subtitle">Removed from the original order</h3>
        <p class="util-muted">
            The provider marked these lines removed. They are not expected in the demo order and are not
            compared.
        </p>
        <ul class="ot-removed-items">
            <?php foreach ($comparison->removedItems as $removed): ?>
                <li>
                    <?= Html::encode($removed->name ?? 'Unnamed item') ?>
                    <?php if ($removed->quantityLabel() !== null): ?>
                        &times;<?= Html::encode($removed->quantityLabel()) ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<p class="util-muted">
    These are differences, not a score. No accuracy percentage, weighting or pass mark is calculated
    here; a later scoring pass reads the same comparison this page renders.
</p>
