<?php

declare(strict_types=1);

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Domain\Comparison\ComparisonScore;
use App\OrderTesting\Domain\Comparison\ComparisonSection;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\Comparison\OrderComparison;
use App\OrderTesting\Domain\Comparison\SectionScore;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\TestAttempt;
use App\OrderTesting\Web\DemoOrdersAsset;
use App\OrderTesting\Web\PaymentDisplay;
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
 * What a status is called on this page — the same words the receipt comparison uses, so the two pages
 * describe one comparison in one vocabulary. "Extra in demo" is said from the original's side, as
 * "Missing in original", exactly as the receipt says it.
 */
$statusWords = static fn(ComparisonStatus $status): string => match ($status) {
    ComparisonStatus::Match => 'Match',
    ComparisonStatus::Different => 'Different',
    ComparisonStatus::MissingInDemo => 'Missing in demo',
    ComparisonStatus::ExtraInDemo => 'Missing in original',
    ComparisonStatus::Uncomparable => 'Not available',
};

/**
 * The verdict beside a demo value: words, never a symbol.
 *
 * A match prints nothing a sighted reader sees — the page reads as agreement unless something says
 * otherwise, so the few rows that differ are the ones that stand out. A screen reader still hears
 * "Match". Everything else is a short label, because "missing" and "different" are not the same thing
 * and a symbol could not say which.
 *
 * A line item is headed by the verdict the score gave it, not the comparator's: a line whose only
 * difference is its unit price scored as a match, and reads as one. See `ComparisonScorer::itemVerdict()`.
 */
$verdict = static function (FieldComparison|ItemComparison $c) use ($statusWords): string {
    $status = $c instanceof ItemComparison ? ComparisonScorer::itemVerdict($c) : $c->status;
    $words = $statusWords($status);

    if ($status === ComparisonStatus::Match) {
        return '<span class="util-visually-hidden">' . Html::encode($words) . '</span>';
    }

    return '<span class="ot-mark ot-mark--' . Html::encode($status->modifier()) . '">'
        . Html::encode($words) . '</span>';
};

/**
 * A row shown for reference and counted by nothing: said so, quietly, where a verdict would sit.
 *
 * Printed beside every demo unit price, whether or not it matches, so a reader is never left to take a
 * price difference for a mistake the score charged.
 */
$notScored = '<span class="ot-mark ot-mark--note">Not scored</span>';

/** Line-item rows the score never reads, shown for reference only. */
$referenceItemFields = ['Unit price'];

/**
 * The modifier rows, printed as the list they are rather than one comma-joined sentence.
 *
 * Read from the line's own lists — each entry is one modifier exactly as the document named it — so
 * nothing is split on a comma that might belong inside a name.
 *
 * @var array<string, Closure(NormalizedItem): list<string>> $modifierLists
 */
$modifierLists = [
    'Options' => static fn(NormalizedItem $i): array => $i->options,
    'Additions' => static fn(NormalizedItem $i): array => $i->additions,
    'Combinations' => static fn(NormalizedItem $i): array => $i->combinations,
];

/** @param list<string> $entries */
$list = static function (array $entries): string {
    if ($entries === []) {
        return '<span class="util-muted">&mdash;</span>';
    }

    $out = '<ul class="ot-list">';

    foreach ($entries as $entry) {
        $out .= '<li class="ot-list__item">' . Html::encode($entry) . '</li>';
    }

    return $out . '</ul>';
};

/** The section headings as a reader expects them. The comparison's own titles stay its own. */
$sectionHeading = static fn(string $title): string => match ($title) {
    'Customer' => 'Customer details',
    'Order' => 'Order details',
    'Delivery address' => 'Delivery address',
    default => $title,
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
        // Shown, never scored, and the reason differs: an informational section is never scored by
        // design, everything else here simply had nothing comparable in it.
        $why = $section->informationalOnly
            ? 'Shown for reference and not scored.'
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
        $overallParts[] = $sectionHeading($scored->title) . ' ' . $scoredPercent . '% at '
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
    $weightNote[] = $sectionHeading($weighted) . ' ' . number_format($share, 2) . '%';
}

$weightTotal = number_format(array_sum($score->weighting), 2);

$overallPercent = $score->displayPercent();
$overallTone = $overallPercent === null
    ? 'unknown'
    : ($overallPercent >= 90 ? 'good' : ($overallPercent >= 70 ? 'fair' : 'poor'));

/**
 * The line's own heading inside an invoice: what was ordered.
 *
 * The product code is not printed — here or as a row: it is the kitchen's routing code, not something
 * the trainee is checked on. It is still in the stored order, and lines are never paired on it.
 */
$itemTitle = static function (?NormalizedItem $item): string {
    if ($item === null) {
        return '<span class="util-muted">Not on this order</span>';
    }

    return Html::encode($item->name ?? 'Unnamed item');
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
    // Customer count and Status are not shown — and, read from the same list, not scored.
    $fields = $keepFilled(ComparisonScorer::shownFields($section));

    if ($fields === []) {
        continue;
    }

    if ($section->title === 'Totals') {
        $totalsSections[] = ['title' => $section->title, 'fields' => $fields];

        continue;
    }

    $leadingSections[] = ['title' => $section->title, 'fields' => $fields];
}

/*
 * Payment details, decided once from BOTH orders and handed to both panels.
 *
 * The card, its expiry and its billing ZIP, compared — and scored with Order details, under whose
 * heading they print. A row exists when either order has something to print in it, so both invoices
 * put out the same rows and stay level; the side without a value prints a dash.
 *
 * The processing fee goes in Totals, where it is part of the total it explains; it appears when either
 * order was paid by card or was charged one.
 */
$paymentFields = $keepFilled($comparison->payment);
$showFeeRow = PaymentDisplay::showsFee($comparison->original, $comparison->demo);

/**
 * The same rule inside each line item, so an empty Options or Instructions row goes as well — and the
 * product code, which this page does not show, goes with them.
 *
 * @var array<int, list<FieldComparison>> $itemFields
 */
$itemFields = [];

foreach ($comparison->items as $index => $item) {
    /** @var ItemComparison $item */
    $shownItemFields = [];

    foreach ($item->fields as $field) {
        if (!in_array($field->label, ComparisonScorer::HIDDEN_ITEM_FIELDS, true)) {
            $shownItemFields[] = $field;
        }
    }

    $itemFields[$index] = $keepFilled($shownItemFields);
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
    $sectionHeading,
    $notScored,
    $referenceItemFields,
    $modifierLists,
    $list,
    $paymentFields,
    $showFeeRow,
): string {
    $isDemo = $side === 'demo';
    $pick = static fn(FieldComparison $f): ?string => $isDemo ? $f->demo : $f->original;
    $mark = static fn(FieldComparison|ItemComparison $c): string => $isDemo ? $verdict($c) : '';

    // A row with nothing to say beside its value prints no verdict slot, so its value sits where the
    // same value sits on the original panel — the totals in particular end flush with each other.
    $line = static function (string $label, string $rendered, string $markup, string $extra = '') use ($isDemo): string {
        return '<div class="ot-book__line' . $extra . '">'
            . '<dt class="ot-book__term">' . Html::encode($label) . '</dt>'
            . '<dd class="ot-book__desc">' . $rendered
            . ($isDemo && $markup !== '' ? '<span class="ot-book__mark">' . $markup . '</span>' : '')
            . '</dd></div>';
    };

    // A demo row that disagrees is tinted as well as labelled, so a reader scanning the column finds the
    // few rows that matter without reading every value.
    $tint = static fn(FieldComparison $f): string => $isDemo && $f->status !== ComparisonStatus::Match
        ? ' ot-book__line--' . $f->status->modifier()
        : '';

    $out = '<section class="ot-book ot-book--' . Html::encode($side) . '" aria-label="' . Html::encode($kind) . '">';

    // The name only when the document has one: a placeholder dash under the heading told the reader
    // nothing. The phone is not repeated here — it is the first row under Customer details.
    $name = $order?->customer->displayName();

    $out .= '<header class="ot-book__head">'
        . '<span class="ot-book__kind">' . Html::encode($kind) . '</span>'
        . '<span class="ot-book__number">#' . Html::encode((string) $orderId) . '</span>'
        . ($name === null || trim($name) === '' ? '' : '<span class="ot-book__who">' . Html::encode($name) . '</span>')
        . '</header>';

    foreach ($leadingSections as $section) {
        $out .= '<div class="ot-book__section"><h3 class="ot-book__section-title">'
            . Html::encode($sectionHeading($section['title']))
            . ($isDemo ? $sectionScore($score->section($section['title'])) : '')
            . '</h3><dl class="ot-book__lines">';

        // The payment method is one of the Order section's scored fields. It is printed under its own
        // heading with the card details below, and all of them share the one Order details score.
        $payment = null;

        foreach ($section['fields'] as $field) {
            if ($section['title'] === 'Order' && $field->label === 'Payment method') {
                $payment = $field;

                continue;
            }

            $out .= $line($field->label, $value($pick($field)), $mark($field), $tint($field));
        }

        if ($section['title'] === 'Order' && ($payment !== null || $paymentFields !== [])) {
            $out .= '</dl><h4 class="ot-book__subhead">Payment details</h4><dl class="ot-book__lines">';

            if ($payment !== null) {
                $out .= $line($payment->label, $value($pick($payment)), $mark($payment), $tint($payment));
            }

            // Card, expiry, billing ZIP — each with its verdict. A detail only one order kept reads
            // "Not available" and is not counted; see `OrderComparator::payment()`.
            foreach ($paymentFields as $field) {
                $shown = $pick($field);
                $out .= $line(
                    $field->label,
                    $shown === NormalizedOrder::CARD_NOT_RECORDED
                        ? '<span class="util-muted">' . Html::encode($shown) . '</span>'
                        : $value($shown),
                    $mark($field),
                    $tint($field),
                );
            }
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
                $rendered = isset($modifierLists[$field->label])
                    ? ($mine === null ? $value(null) : $list($modifierLists[$field->label]($mine)))
                    : $value($pick($field));

                // Shown, never scored: no verdict and no tint. Every demo price says so — matching or
                // not — so the note reads as a property of the row rather than as a finding about it.
                // A line the demo does not have prints a dash and no note.
                if (in_array($field->label, $referenceItemFields, true)) {
                    $shown = $pick($field);

                    $out .= $line(
                        $field->label,
                        $rendered,
                        $isDemo && $shown !== null && trim($shown) !== '' ? $notScored : '',
                    );

                    continue;
                }

                $out .= $line($field->label, $rendered, $mark($field), $tint($field));
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
    //
    // For reference only: no score, no verdicts, no tint. Each side prints its own amounts as stored,
    // and nothing about them counts towards the overall.
    foreach ($totalsSections as $section) {
        $out .= '<div class="ot-book__section ot-book__section--totals">'
            . '<h3 class="ot-book__section-title">' . Html::encode($section['title']) . '</h3>'
            . '<dl class="ot-book__lines">';

        $last = array_key_last($section['fields']);

        foreach ($section['fields'] as $key => $field) {
            // The card processing fee is already inside the total, so it is printed just above it —
            // then the lines above the total add up to it.
            if ($key === $last && $showFeeRow) {
                $out .= $line('Card processing fee', $value($order?->totals->processingFee), '');
            }

            // The grand total is the figure anybody actually looks for, so it is set apart rather than
            // left as the last of seven identical rows.
            $out .= $line(
                $field->label,
                $value($pick($field)),
                '',
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
        <?php // `!== null` rather than `isAvailable()`: the same question, but it narrows the type.
        ?>
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
        <div>
            <dt>Store</dt>
            <dd><?= Html::encode($storeName) ?> &middot; Store #<?= Html::encode((string) $sourceId) ?></dd>
        </div>
        <div>
            <dt>Source order</dt>
            <dd>#<?= Html::encode((string) $demo->sourceOrderId) ?></dd>
        </div>
        <div>
            <dt>Demo order</dt>
            <dd>#<?= Html::encode((string) $demo->demoOrderId) ?></dd>
        </div>
        <div>
            <dt>Tested by</dt>
            <dd>
                <?php if ($attempt === null): ?>
                    <?php // Never invented. See the import rules: attribution is written once, when a
                    ?>
                    <?php // demo order first arrives, and only if an attempt was open for it.
                    ?>
                    <span class="util-muted">Unknown &mdash; no matched attempt</span>
                <?php else: ?>
                    <?php
                    // Who practised, not the state of their attempt: whether it later expired is
                    // bookkeeping about the attempt, not a fact about this comparison.
                    ?>
                    <?= Html::encode($attempt->initiatedByType->label()) ?>
                    <?= Html::encode($attempt->initiatedByName ?? ('#' . $attempt->initiatedById)) ?>
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
<details class="ot-books__note">
    <summary>How this score is calculated</summary>
    <p class="util-muted">
        Each scored section counts the rows shown in it: a row that agrees counts in full, one that differs
        or is missing from the demo counts as nothing. A field both orders left empty is not shown and not
        counted &mdash; silence is not agreement. A value only the demo order has is shown as
        &ldquo;Missing in original&rdquo; but is not counted against it, because the original frequently
        holds no name, state or email to compare. A row marked &ldquo;Not available&rdquo; could not be
        compared and is not counted either.
    </p>
    <p class="util-muted">
        <strong>Customer details</strong> score the customer fields shown; the customer count is not shown
        or scored. <strong>Order details</strong> score the order type and the payment details together, as
        one score: payment method, card (last four digits), expiration date and billing ZIP. A card detail
        counts only when both orders recorded it &mdash; when only one did, the other was either not paid
        by card, which the payment method already counts, or its card details were not kept. The order
        status is not shown or scored. <strong>Delivery address</strong> scores city, state, postal code and
        street 2; the original order&rsquo;s street line is never stored, so the street is compared through
        Destination, the full address line, which counts once. A take-out order has no address to score,
        and the section then leaves the overall rather than counting as zero.
    </p>
    <p class="util-muted">
        <strong>Order items</strong> count one unit per line. A line the demo missed, or one it added,
        counts once and for nothing &mdash; once, not once per field, so a single mistake is charged a
        single time. Within a matched line, the product, additions, combinations and instructions count
        once each and the quantity counts double; Options is not counted on its own because it repeats
        Additions and Combinations. The unit price is shown, and a different price is pointed out, but it
        is not scored. <strong>Totals</strong> are shown for reference and are not scored.
    </p>
    <p class="util-muted">
        The overall is the weighted average of the sections that could be scored &mdash;
        <?= Html::encode(implode(', ', $weightNote)) ?>, <?= Html::encode($weightTotal) ?>% in all.
        Figures are rounded to whole percentages, except that a section short of complete never prints
        100 and one short of nothing never prints 0. The figure is this demo order measured against this
        original, and nothing wider: not the trainee, not the call, not the store.
    </p>
</details>