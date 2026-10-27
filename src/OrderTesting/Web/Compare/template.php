<?php

declare(strict_types=1);

use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\Comparison\SectionScore;
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
 * @var ComparisonScore $score how closely the demo reproduced the original, section by section
 * @var array<string, string> $routes the addresses this surface builds its links from
 */

$assetManager->register(DemoOrdersAsset::class);

// Two invoices need more than the 1180px a reading column wants. Opt-in, so no other page moves.
$this->setParameter('wideContent', true);

$this->setTitle('Order comparison #' . $demo->sourceOrderId . ' / demo #' . $demo->demoOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    [
        'label' => 'Demo orders',
        'route' => $routes['demoOrders'],
        'arguments' => ['sourceId' => $sourceId, 'sourceOrderId' => $demo->sourceOrderId],
    ],
    ['label' => 'Demo #' . $demo->demoOrderId],
]);

$listUrl = $urlGenerator->generate($routes['demoOrders'], [
    'sourceId' => $sourceId,
    'sourceOrderId' => $demo->sourceOrderId,
]);

// The same demo order as a printable receipt. A page of its own, not a dialog: it is a document, and a
// document wants an address somebody can keep, print or send.
$receiptUrl = $urlGenerator->generate($routes['demoReceipt'], [
    'sourceId' => $sourceId,
    'sourceOrderId' => $demo->sourceOrderId,
    'demoOrderId' => $demo->demoOrderId,
]);

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
 * A section's score, drawn beside its heading on the demo panel.
 *
 * Only on the demo panel, because the number is a statement about what the trainee entered — the same
 * reason the per-field verdicts are only on that side. Side by side the two panels share one row track
 * per element, so a heading that grows for the badge grows on both sides and the invoices stay level
 * (see the subgrid block in `demo-orders.css`); stacked, there is nothing to stay level with.
 *
 * The coverage line is not decoration. A reader must be able to count the rows below the heading and
 * arrive at this number, so the denominator says how many of the rows they can see were scored — and
 * `--thin` says so louder when the number rests on less than half of them.
 */
$sectionScore = static function (?SectionScore $section): string {
    if ($section === null) {
        return '';
    }

    if (!$section->isScored()) {
        // Shown, never scored, and the reason differs: Delivery address is redacted out of both
        // documents by policy, everything else here simply had nothing comparable in it.
        $why = $section->informationalOnly
            ? 'Shown for reference. Not scored: the street line is redacted from both documents.'
            : 'Nothing in this section could be compared, so it is left out of the overall score.';

        return '<span class="ot-sscore ot-sscore--none" title="' . Html::encode($why) . '">'
            . '<span class="ot-sscore__value">' . ($section->informationalOnly ? 'Not scored' : 'N/A')
            . '</span></span>';
    }

    $percent = $section->displayPercent() ?? 0;
    $tone = $percent >= 90 ? 'good' : ($percent >= 70 ? 'fair' : 'poor');
    $unit = $section->scoredUnits === 1 ? $section->unit : $section->unit . 's';

    $title = $section->weight > 0
        ? 'Worth ' . number_format($section->weight, 2) . '% of the overall score.'
        : 'Not counted towards the overall score.';

    return '<span class="ot-sscore ot-sscore--' . $tone . '" title="' . Html::encode($title) . '">'
        . '<span class="ot-sscore__value">' . $percent . '%</span>'
        . '<span class="ot-sscore__meta' . ($section->hasLowCoverage() ? ' ot-sscore__meta--thin' : '') . '">'
        . Html::encode($section->scoredUnits . ' of ' . $section->visibleUnits . ' ' . $unit) . '</span>'
        . '</span>';
};

/*
 * The overall figure's own explanation, built from what the sections already carry.
 *
 * The weighting is not restated here: each section brought its own weight, so there is exactly one
 * table of weights in the application and this is a reader of it. A section that could not be scored
 * is absent from the breakdown rather than shown as a zero, because that is how it was treated.
 */
$overallParts = [];

foreach ($score->sections as $scored) {
    $scoredPercent = $scored->displayPercent();

    if ($scored->weight > 0 && $scoredPercent !== null) {
        $overallParts[] = $scored->title . ' ' . $scoredPercent . '% at '
            . number_format($scored->weight, 2) . '%';
    }
}

$overallTitle = $score->isAvailable()
    ? 'Weighted across the sections that could be scored: ' . implode(', ', $overallParts)
        . '. This demo order against its original — not an average over the call.'
    : ($score->unavailableReason ?? 'Nothing in this comparison could be scored.');

/*
 * The weighting, in words, from the same place the arithmetic took it. A reader who wants to check the
 * figure needs the weights; restating them as prose would have been a second copy to keep in step.
 *
 * The whole table rather than this order's applicable part: it is the policy, and it is the same on
 * every comparison. What this particular order's figure was made of is on the card's own tooltip,
 * where a section that could not be scored is simply absent.
 */
$weightNote = [];

foreach ($score->weighting as $weighted => $share) {
    $weightNote[] = $weighted . ' ' . number_format($share, 2) . '%';
}

$weightTotal = number_format(array_sum($score->weighting), 2);

$overallPercent = $score->displayPercent();
$overallTone = $overallPercent === null
    ? 'unknown'
    : ($overallPercent >= 90 ? 'good' : ($overallPercent >= 70 ? 'fair' : 'poor'));

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
    $score,
    $sectionScore,
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
            . Html::encode($section['title'])
            . ($isDemo ? $sectionScore($score->section($section['title'])) : '')
            . '</h3><dl class="ot-book__lines">';

        foreach ($section['fields'] as $field) {
            $out .= $line($field->label, $value($pick($field)), $mark($field));
        }

        $out .= '</dl></div>';
    }

    // ---- the lines themselves -------------------------------------------------------------------
    $out .= '<div class="ot-book__section ot-book__section--items"><h3 class="ot-book__section-title">'
        . 'Order items'
        . ($isDemo ? $sectionScore($score->section('Order items')) : '')
        . '</h3>';

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
            . '<h3 class="ot-book__section-title">' . Html::encode($section['title'])
            . ($isDemo ? $sectionScore($score->section($section['title'])) : '')
            . '</h3><dl class="ot-book__lines">';

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
</div>

<?php
// A row of its own, rather than the shared header's action slot.
//
// `.page-header` lays its title and its actions out with `space-between` and lets them wrap. This
// page's subtitle is a sentence, so the row always wrapped — and a wrapped row has no second item to
// be spaced against, which left the controls huddled at one end with the width unused. Worse, where
// they ended up depended on how long the subtitle happened to be.
//
// So the controls get their own full-width row: the three actions at the left, the score at the right,
// and nothing about the heading above can move either of them.
?>
<div class="ot-toolbar">
    <div class="ot-toolbar__actions">
        <?php
        /*
         * "Sync Original Order Items" is gone (Stage 7a).
         *
         * It posted to the per-order items endpoint, which no longer exists: an order's lines now
         * arrive inside the order from the Case Order response, so there is nothing for a per-order
         * control to fetch. The availability notice above still says when the left-hand panel has no
         * items — see `ItemAvailability` — and the remedy is a Case Order synchronisation, which
         * covers every order the provider returns in one request.
         */
?>
        <?php
// `btn` alone draws no box — the base class is layout and typography, and a variant supplies
// the surface. Without one this sat in a row of buttons as bare link text. `btn--secondary` is
// the same variant View Receipt uses, so the three controls now read as one set.
//
// The visible label is shortened and the accessible name keeps the whole sentence, which still
// contains it — a screen reader is told which call, and the toolbar does not grow to say so.
?>
        <a class="btn btn--secondary" href="<?= Html::encode($listUrl) ?>"
           aria-label="All demo orders for this call">
            <span aria-hidden="true">📋</span> All Demo Orders
        </a>
        <a class="btn btn--secondary" href="<?= Html::encode($receiptUrl) ?>">
            <span aria-hidden="true">🧾</span> View Receipt
        </a>
    </div>

    <?php
    // Not an action, so it is not in the action group: nothing here is pressed. It is the one figure
    // the page exists to produce, captioned with its scope — this demo order against this original,
    // and nothing wider. An uncaptioned percentage on a page about a trainee's work becomes a figure
    // about the trainee.
    //
    // Unavailable prints as unavailable rather than as 0%. The reason is already on this page — the
    // mirror notice above, or the item-synchronisation alert below — so it is not repeated here.
?>
    <div class="ot-score ot-score--<?= Html::encode($overallTone) ?>"
         title="<?= Html::encode($overallTitle) ?>">
        <span class="ot-score__label">Overall match score</span>
        <?php // `!== null` rather than `isAvailable()`: the same question, but it narrows the type.?>
        <span class="ot-score__value<?= $overallPercent === null ? ' ot-score__value--unavailable' : '' ?>">
            <?= $overallPercent === null
            ? 'Not scored'
            : Html::encode($overallPercent . '%') ?>
        </span>
        <span class="ot-score__scope">
            <?= $score->isAvailable() ? 'this demo order' : 'see the note below' ?>
        </span>
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

<?php
// How the figures above were arrived at, in the same place as the figures.
//
// Not a nicety: the score is only defensible if a reader can count the rows on this page and reach the
// same number. So every rule that moves it is stated — including the two that make a row count for
// nothing and the one that makes a row count for nothing at all — and the weights are printed from the
// same table the arithmetic used rather than retyped here.
?>
<p class="util-muted ot-books__note">
    <strong>How this is scored.</strong>
    Each section counts the rows shown above it: a row that agrees counts in full, one that differs or
    is missing counts as nothing. A field both orders left empty is not shown and not counted &mdash;
    silence is not agreement. A value only the demo order has is shown but is not counted against it
    here, because the mirror frequently holds no name or email to compare; on the line items it <em>is</em>
    counted, because the provider states which lines the order had. A line the demo missed, or one it
    added, counts once and for nothing &mdash; once, not once per field, so a single mistake is charged
    a single time. Within a matched line, quantity and unit price count double, and the Options row is
    not counted on its own because it repeats Additions and Combinations. Within Totals, the grand
    total counts triple. Delivery address is shown and never scored: its street line is redacted from
    both documents. Figures are rounded to whole percentages, except that a section short of complete
    never prints 100 and one short of nothing never prints 0.
    Weighting &mdash; <?= Html::encode(implode(', ', $weightNote)) ?>,
    <?= Html::encode($weightTotal) ?>% in all.
    The figure is this demo order measured against this original, and nothing wider: not the trainee,
    not the call, not the store.
</p>
