<?php

declare(strict_types=1);

use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\TestAttempt;
use App\OrderTesting\Web\DemoOrdersAsset;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Two invoices, side by side.
 *
 * ## The rule this template exists to obey
 *
 * **The left panel renders only the original order. The right panel renders only the demo order.**
 * Neither reaches across. A reader can cover one half of the screen and still be looking at a complete,
 * truthful invoice — which is the whole point, and what the earlier shared four-column grid could not
 * do however it was styled.
 *
 * The verdict belongs to the demo side, because it is a statement about what the trainee entered. It is
 * rendered beside the demo value rather than in a column of its own, so the answer sits where the thing
 * it judges is.
 *
 * ## Why both panels walk the same field list
 *
 * Alignment. Each panel iterates `$comparison->sections` and `$comparison->items` in the same order and
 * emits one row per entry, so Customer faces Customer and the fourth line of one faces the fourth line
 * of the other. A side absent from a pair still emits its row, muted — leaving it out would slide
 * everything below it out of step and quietly make the two invoices disagree about which line is which.
 *
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

// Two invoices need more than the 1180px a reading column wants. Opt-in, so no other page moves.
$this->setParameter('wideContent', true);

$this->setTitle('Order comparison #' . $demo->sourceOrderId . ' / demo #' . $demo->demoOrderId);
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

// Fetches the ORIGINAL order's items — the left-hand panel — and nothing else. The demo order on the
// right is a file somebody's listener wrote and is never touched here; its id travels with the form
// only so that this exact comparison is what reloads afterwards.
//
// The literal route name, not a constant: this module may not name `App\Order58`, which owns the
// action. A name is not a namespace, so the module boundary holds.
$syncItemsUrl = $urlGenerator->generate('order58.order-items.sync');

/** A value as the document recorded it, or an explicit blank. Never guessed at, never reformatted. */
$value = static fn(?string $v): string => $v === null || $v === ''
    ? '<span class="util-muted">&mdash;</span>'
    : Html::encode($v);

/**
 * The verdict beside a demo value.
 *
 * A match is a tick and nothing else — twenty-seven of them spelled out would bury the three that
 * matter. Everything else keeps its words, because "missing" and "extra" are not opposites of "match"
 * and a bare mark could not tell them apart. The `title` carries the full wording for the tick, and
 * `util-visually-hidden` repeats it for a screen reader, so the compact form is never the only copy.
 */
$verdict = static function (FieldComparison|ItemComparison $c): string {
    $label = $c->status->label();
    $modifier = $c->status->modifier();

    if ($c->status === ComparisonStatus::Match) {
        return '<span class="ot-mark ot-mark--match" title="' . Html::encode($label) . '">'
            . '<span aria-hidden="true">✓</span>'
            . '<span class="util-visually-hidden">' . Html::encode($label) . '</span></span>';
    }

    $glyph = match ($c->status) {
        ComparisonStatus::Different => '✕',
        ComparisonStatus::MissingInDemo => '−',
        ComparisonStatus::ExtraInDemo => '+',
        ComparisonStatus::Uncomparable => '·',
        ComparisonStatus::Match => '✓',
    };

    return '<span class="ot-mark ot-mark--' . Html::encode($modifier) . '">'
        . '<span class="ot-mark__glyph" aria-hidden="true">' . $glyph . '</span>'
        . Html::encode($label) . '</span>';
};

/**
 * The item-synchronisation state, in words an administrator can act on.
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

/** The line's own heading inside an invoice: what was ordered, and its catalogue code. */
$itemTitle = static function (?NormalizedItem $item): string {
    if ($item === null) {
        return '<span class="util-muted">Not on this order</span>';
    }

    $out = Html::encode($item->name ?? 'Unnamed item');

    if ($item->sn !== null) {
        $out .= ' <code>' . Html::encode($item->sn) . '</code>';
    }

    return $out;
};

/**
 * A row both documents left empty is not a row.
 *
 * Decided **here, once, from the shared comparison**, and handed to both panels — never as a condition
 * inside the markup. A side that dropped a row the other kept would slide everything below it out of
 * step, and the two orders would quietly disagree about which line is which.
 *
 * Empty means the document said nothing: `null`, empty, or whitespace. It does not mean zero. `0`,
 * `0.00` and a customer count of `1` are answers, and the comparison turns on them — so a stated zero
 * keeps its row, and only a genuine absence on *both* sides removes one.
 */
$isBlank = static fn(?string $v): bool => $v === null || trim($v) === '';

/** @var list<FieldComparison> $fields */
$keepFilled = static function (array $fields) use ($isBlank): array {
    $kept = [];

    foreach ($fields as $field) {
        /** @var FieldComparison $field */
        if ($isBlank($field->original) && $isBlank($field->demo)) {
            continue;
        }

        $kept[] = $field;
    }

    return $kept;
};

// Totals close an invoice, so the items go above them. Partitioned by title rather than by position:
// if the comparator ever reorders its sections, the worst case is that items fall last, not that a
// section silently disappears.
//
// A section left with no rows goes too, heading and all: that is what makes Delivery address vanish
// for a take-out order without this template knowing what take-out is.
/** @var list<array{title: string, fields: list<FieldComparison>}> $leadingSections */
$leadingSections = [];

/** @var list<array{title: string, fields: list<FieldComparison>}> $totalsSections */
$totalsSections = [];

foreach ($comparison->sections as $section) {
    /** @var ComparisonSection $section */
    $fields = $keepFilled($section->fields);

    if ($fields === []) {
        continue;
    }

    if ($section->title === 'Totals') {
        $totalsSections[] = ['title' => $section->title, 'fields' => $fields];

        continue;
    }

    $leadingSections[] = ['title' => $section->title, 'fields' => $fields];
}

/**
 * The same rule inside each line item, so an empty Options or Instructions row goes as well.
 *
 * @var array<int, list<FieldComparison>> $itemFields
 */
$itemFields = [];

foreach ($comparison->items as $index => $item) {
    /** @var ItemComparison $item */
    $itemFields[$index] = $keepFilled($item->fields);
}

/**
 * One invoice.
 *
 * Called twice, once per side, and told which side it is. Everything it prints comes from that side
 * alone: `$side` picks the value, and only the demo panel is given the verdicts to show.
 */
$panel = static function (
    string $side,
    string $kind,
    int $orderId,
    ?NormalizedOrder $order,
) use (
    $comparison,
    $leadingSections,
    $totalsSections,
    $itemFields,
    $value,
    $verdict,
    $itemTitle,
): string {
    $isDemo = $side === 'demo';
    $pick = static fn(FieldComparison $f): ?string => $isDemo ? $f->demo : $f->original;
    $mark = static fn(FieldComparison|ItemComparison $c): string => $isDemo ? $verdict($c) : '';

    $line = static function (string $label, string $rendered, string $markup, string $extra = '') use ($isDemo): string {
        return '<div class="ot-book__line' . $extra . '">'
            . '<dt class="ot-book__term">' . Html::encode($label) . '</dt>'
            . '<dd class="ot-book__desc">' . $rendered
            . ($isDemo ? '<span class="ot-book__mark">' . $markup . '</span>' : '')
            . '</dd></div>';
    };

    $out = '<section class="ot-book ot-book--' . Html::encode($side) . '" aria-label="' . Html::encode($kind) . '">';

    // Read once, so an unreadable order prints the same placeholder in both slots rather than half a
    // header. `$customer` is non-null whenever `$order` is; the nullsafe chain is about the order.
    $customer = $order?->customer;
    $phone = $customer?->phone;

    $out .= '<header class="ot-book__head">'
        . '<span class="ot-book__kind">' . Html::encode($kind) . '</span>'
        . '<span class="ot-book__number">#' . Html::encode((string) $orderId) . '</span>'
        . '<span class="ot-book__who">' . Html::encode($customer?->displayName() ?? '-') . '</span>'
        . '<span class="ot-book__phone">'
        . ($phone === null ? '<span class="util-muted">-</span>' : Html::encode($phone))
        . '</span></header>';

    foreach ($leadingSections as $section) {
        $out .= '<div class="ot-book__section"><h3 class="ot-book__section-title">'
            . Html::encode($section['title']) . '</h3><dl class="ot-book__lines">';

        foreach ($section['fields'] as $field) {
            $out .= $line($field->label, $value($pick($field)), $mark($field));
        }

        $out .= '</dl></div>';
    }

    // ---- the lines themselves -------------------------------------------------------------------
    $out .= '<div class="ot-book__section ot-book__section--items"><h3 class="ot-book__section-title">'
        . 'Order items</h3>';

    if ($comparison->items === []) {
        $out .= '<p class="util-muted">'
            . ($comparison->itemsWereCompared() ? 'No line items on this order.' : 'No line items to show.')
            . '</p>';
    } else {
        $out .= '<div class="ot-items">';

        foreach ($comparison->items as $index => $item) {
            /** @var ItemComparison $item */
            $mine = $isDemo ? $item->demo : $item->original;

            $out .= '<article class="ot-book__item' . ($mine === null ? ' ot-book__item--absent' : '') . '">'
                . '<header class="ot-book__item-head">'
                . '<h4 class="ot-book__item-name">' . $itemTitle($mine) . '</h4>'
                . ($isDemo ? '<span class="ot-book__mark">' . $verdict($item) . '</span>' : '')
                . '</header><dl class="ot-book__lines">';

            foreach ($itemFields[$index] ?? [] as $field) {
                /** @var FieldComparison $field */
                $out .= $line($field->label, $value($pick($field)), $mark($field));
            }

            $out .= '</dl></article>';
        }

        $out .= '</div>';
    }

    // Lines the provider marked removed belong to the original invoice and are not expected opposite.
    //
    // The demo panel still emits its own block rather than nothing. Both invoices must put out the same
    // number of rows in the same order for the shared row grid to pair them up, and a side that quietly
    // skipped a row would slide its totals one line out of step with the other's. So the counterpart is
    // a real, labelled statement of why this side has no such lines — not a spacer.
    if ($comparison->removedItems !== []) {
        $out .= '<div class="ot-book__removed"><h4 class="ot-book__item-name">'
            . ($isDemo ? 'Removed lines' : 'Removed from the original order') . '</h4>';

        if ($isDemo) {
            $out .= '<p class="util-muted">The provider removed these lines from the original order, so '
                . 'they are not expected here and nothing is missing.</p></div>';
        } else {
            $out .= '<p class="util-muted">The provider marked these lines removed. They are not expected '
                . 'in the demo order and are not compared.</p><ul class="ot-removed-items">';

            foreach ($comparison->removedItems as $removed) {
                $out .= '<li>' . Html::encode($removed->name ?? 'Unnamed item')
                    . ($removed->quantityLabel() !== null ? ' &times;' . Html::encode($removed->quantityLabel()) : '')
                    . '</li>';
            }

            $out .= '</ul></div>';
        }
    }

    $out .= '</div>';

    // ---- totals close the invoice ----------------------------------------------------------------
    foreach ($totalsSections as $section) {
        $out .= '<div class="ot-book__section ot-book__section--totals">'
            . '<h3 class="ot-book__section-title">' . Html::encode($section['title']) . '</h3>'
            . '<dl class="ot-book__lines">';

        $last = array_key_last($section['fields']);

        foreach ($section['fields'] as $key => $field) {
            // The grand total is the figure anybody actually looks for, so it is set apart rather than
            // left as the last of seven identical rows.
            $out .= $line(
                $field->label,
                $value($pick($field)),
                $mark($field),
                $key === $last ? ' ot-book__line--grand' : '',
            );
        }

        $out .= '</dl></div>';
    }

    return $out . '</section>';
};
?>

<div class="page-header">
    <div>
        <h1 class="page-header__title">Order Comparison</h1>
        <p class="page-header__subtitle">
            <?= Html::encode($storeName) ?> &middot; Store #<?= Html::encode((string) $sourceId) ?>
            &mdash; what the call actually ordered, beside what the trainee entered in Order58.
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
        there is nothing to compare against. Everything the trainee entered is shown on the right.
        Syncing the store for the date this order was placed will fill the left-hand invoice in; nothing
        here is lost in the meantime.
    </div>
<?php endif; ?>

<?php if ($comparison->itemNote() !== ''): ?>
    <?php
    // Why the line items below read as they do. Two different kinds of message share this one place:
    // "these cannot be compared" and "these are from an earlier fetch". Both are statements about the
    // synchronisation, and neither is a statement about the trainee — which is exactly what the old
    // behaviour got wrong when it reported every demo line as invented.
    ?>
    <div class="alert alert--<?= Html::encode($comparison->itemAvailability->modifier()) ?>" role="status">
        <?= Html::encode($comparison->itemNote()) ?>
        <?php if ($comparison->itemsSyncedAt !== null && $comparison->itemAvailability->isStale()): ?>
            Last synchronized <?= Html::encode($comparison->itemsSyncedAt) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="ot-books">
    <?= $panel('original', 'Original order', $demo->sourceOrderId, $comparison->original) ?>
    <?= $panel('demo', 'Demo / test order', $demo->demoOrderId, $comparison->demo) ?>
</div>

<p class="util-muted ot-books__note">
    These are differences, not a score. No accuracy percentage,
    weighting or pass mark is calculated here; a later scoring pass reads the same comparison this page
    renders.
</p>
