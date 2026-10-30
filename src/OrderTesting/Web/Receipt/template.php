<?php

declare(strict_types=1);

use App\OrderTesting\Application\Comparison\ComparisonScorer;
use App\OrderTesting\Application\Comparison\ReceiptComparison;
use App\OrderTesting\Domain\Comparison\ComparisonStatus;
use App\OrderTesting\Domain\Comparison\FieldComparison;
use App\OrderTesting\Domain\Comparison\ItemComparison;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\NormalizedOrder;
use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Web\MoneyDisplay;
use App\OrderTesting\Web\PaymentDisplay;
use App\OrderTesting\Web\ReceiptAsset;
use App\OrderTesting\Web\VerdictWords;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * The original order and the demo order, as two receipts, with what differs between them.
 *
 * ## Two documents, each true on its own
 *
 * Each card is rendered from its own order and nothing else. Where one side has no value it prints a
 * dash — it never borrows the other's, because the entire question here is whether the two agree. The
 * verdict badges live on the demo side, which is where a statement about what the trainee entered
 * belongs.
 *
 * ## Why both cards walk one list
 *
 * Every row comes from the shared comparison, so row n of the left card is row n of the right one, and
 * side by side the two share a set of grid tracks so row n is also the same height on both. A card that
 * decided its own rows would slide out of step with the other and the two receipts would disagree about
 * which line is which — which is the one thing a comparison may not do. This is also why every element
 * below is a direct child of its card: those children are the shared rows.
 *
 * ## Money
 *
 * Written to two decimals by {@see MoneyDisplay}, the one formatter both comparison pages use, because a
 * receipt is read by a person. The verdict beside it was decided by
 * {@see \App\OrderTesting\Application\Comparison\ComparisonRules::moneyEquals()} on the stored strings,
 * so rounding here can never hide a difference or invent one. Nothing is recalculated: a total is the
 * total the document stored, not the lines added up.
 *
 * @var Yiisoft\View\WebView $this
 * @var UrlGeneratorInterface $urlGenerator
 * @var Yiisoft\Assets\AssetManager $assetManager
 * @var int $sourceId
 * @var string $storeName
 * @var DemoOrder $demo
 * @var ReceiptComparison $receipt
 * @var array<string, string> $routes the addresses this surface builds its links from
 */

$assetManager->register(ReceiptAsset::class);
$this->setParameter('wideContent', true);

$this->setTitle('Receipt comparison — #' . $demo->sourceOrderId . ' vs #' . $demo->demoOrderId);
$this->setParameter('breadcrumbs', [
    ['label' => $routes['pageLabel'], 'route' => $routes['page']],
    ['label' => $storeName, 'route' => $routes['store'], 'arguments' => ['sourceId' => $sourceId]],
    [
        'label' => 'Demo orders',
        'route' => $routes['demoOrders'],
        'arguments' => ['sourceId' => $sourceId, 'sourceOrderId' => $demo->sourceOrderId],
    ],
    ['label' => 'Receipt #' . $demo->demoOrderId],
]);

// Literal route names, not constants: the constants live in the audio module, which this one may not
// name — not even in a comment, because the boundary test matches the text of the file. A route name
// is not a namespace, so linking to a page another module serves costs nothing; importing its class
// would have made this module depend on that one.
$backUrl = $urlGenerator->generate($routes['demoCompare'], [
    'sourceId' => $sourceId,
    'sourceOrderId' => $demo->sourceOrderId,
    'demoOrderId' => $demo->demoOrderId,
]);

$comparison = $receipt->comparison;

/** Said nothing. Not the same as said zero. */
$blank = static fn(?string $v): bool => $v === null || trim($v) === '';

/** An amount as a person reads it. The comparison was made on the stored string, not on this. */
$money = static function (?string $v): string {
    $shown = MoneyDisplay::amount($v);

    return $shown === null ? '<span class="rc-muted">-</span>' : Html::encode($shown);
};

/**
 * A line's total: quantity times unit price, and only when the document states both.
 *
 * Neither provider stores one. Both operands are printed in the columns to its left, so the figure can
 * be checked by eye — it is arithmetic on two shown numbers, not a value conjured from nowhere. A line
 * missing either operand prints a dash rather than a guess. Multiplied on the decimal digits, not as
 * floats, so the cents printed are the cents the two figures make.
 */
$lineTotal = static function (?NormalizedItem $item): ?string {
    if ($item === null) {
        return null;
    }

    if ($item->total !== null && trim($item->total) !== '') {
        return $item->total;
    }

    return MoneyDisplay::times($item->quantityLabel(), $item->unitPrice);
};

/** Any other value, or a dash. Never the opposite document's. */
$text = static fn(?string $v): string => $v === null || trim($v) === ''
    ? '<span class="rc-muted">-</span>'
    : Html::encode(trim($v));

/**
 * The verdict, in words and never a symbol.
 *
 * A match prints nothing a sighted reader sees: the receipt reads as agreement unless a row says
 * otherwise, so the rows that differ are the ones that stand out. A screen reader still hears "Match".
 * The words are the comparison page's ({@see VerdictWords}): a product only the demo has is an "Extra
 * product in demo", a field value only the demo has is "Missing in original".
 */
$badge = static function (ComparisonStatus $status, bool $isItem = false): string {
    $words = $isItem ? VerdictWords::item($status) : VerdictWords::field($status);

    if ($status === ComparisonStatus::Match) {
        return '<span class="util-visually-hidden">' . Html::encode($words) . '</span>';
    }

    return '<span class="rc-mark rc-mark--' . Html::encode($status->modifier()) . '">'
        . Html::encode($words) . '</span>';
};

/**
 * When an order was placed, on the store's own clock — the order states its time zone, as Order58's
 * printed receipt does. An order that names none, or a zone this server does not know, is printed in
 * UTC and says so rather than being placed in a zone it never claimed.
 */
$placedAt = static function (?NormalizedOrder $order): ?string {
    $placed = $order?->createdAt;

    if ($order === null || $placed === null) {
        return null;
    }

    $at = new DateTimeImmutable('@' . $placed);
    $zone = $order->timezone;

    return $zone === null || $zone === ''
        ? $at->format('M j, Y g:i A') . ' UTC'
        : $at->setTimezone(new DateTimeZone($zone))->format('M j, Y g:i A T');
};

// Payment rows, decided from BOTH orders so the two receipts stay row for row. See PaymentDisplay.
$showCardRow = PaymentDisplay::showsCard($comparison->original, $comparison->demo);
$showExpiryRow = PaymentDisplay::showsExpiry($comparison->original, $comparison->demo);
$showZipRow = PaymentDisplay::showsBillingZip($comparison->original, $comparison->demo);
$showFeeRow = PaymentDisplay::showsFee($comparison->original, $comparison->demo);

/**
 * The counts above the receipts: filter key, how many, the words, the colour.
 *
 * An extra product has a chip of its own, apart from a field value only the demo has — the first is
 * charged and the second is not. See `ReceiptComparison::EXTRA_PRODUCT`.
 *
 * @var list<array{string, int, string, string}> $chips
 */
$chips = [
    [ComparisonStatus::Match->value, $receipt->count(ComparisonStatus::Match), 'Matched', 'match'],
    [
        ComparisonStatus::Different->value,
        $receipt->count(ComparisonStatus::Different),
        VerdictWords::field(ComparisonStatus::Different),
        'different',
    ],
    [
        ComparisonStatus::MissingInDemo->value,
        $receipt->count(ComparisonStatus::MissingInDemo),
        VerdictWords::field(ComparisonStatus::MissingInDemo),
        'missing',
    ],
    [
        ReceiptComparison::EXTRA_PRODUCT,
        $receipt->extraProducts(),
        VerdictWords::item(ComparisonStatus::ExtraInDemo),
        'extra',
    ],
    [
        ComparisonStatus::ExtraInDemo->value,
        $receipt->count(ComparisonStatus::ExtraInDemo),
        VerdictWords::field(ComparisonStatus::ExtraInDemo),
        'extra',
    ],
    [
        ComparisonStatus::Uncomparable->value,
        $receipt->count(ComparisonStatus::Uncomparable),
        VerdictWords::field(ComparisonStatus::Uncomparable),
        'unknown',
    ],
];

/**
 * The heading a section is printed under.
 *
 * Mapped here rather than renamed in the comparator: those titles are the comparison's own vocabulary
 * and the comparison page prints them too. A receipt wants a receipt's wording, and that is a display
 * decision, so it is made in the view.
 */
$sectionTitle = static fn(string $title): string => match ($title) {
    'Customer' => 'Customer Details',
    'Order' => 'Order Details',
    'Delivery address' => 'Delivery Address',
    default => $title,
};

/** The modifier notes under one line, preferring the specific lists over their flattened form. */
$modifiersOf = static function (?NormalizedItem $item): array {
    if ($item === null) {
        return [];
    }

    $named = array_values(array_filter(
        array_merge($item->additions, $item->combinations),
        static fn(string $v): bool => trim($v) !== '',
    ));

    if ($named !== []) {
        return $named;
    }

    return array_values(array_filter($item->options, static fn(string $v): bool => trim($v) !== ''));
};

/**
 * One receipt.
 *
 * Called twice and told which side it is. Everything it prints comes from the order it was handed;
 * only the demo side is given the verdicts, because the verdict is a statement about the demo.
 */
$card = static function (
    string $side,
    string $kind,
    int $orderNumber,
    ?NormalizedOrder $order,
) use (
    $receipt,
    $comparison,
    $storeName,
    $money,
    $text,
    $blank,
    $badge,
    $modifiersOf,
    $lineTotal,
    $sectionTitle,
    $placedAt,
    $showCardRow,
    $showExpiryRow,
    $showZipRow,
    $showFeeRow,
): string {
    $isDemo = $side === 'demo';
    $pick = static fn(FieldComparison $f): ?string => $isDemo ? $f->demo : $f->original;
    $mark = static fn(FieldComparison|ItemComparison $c): string => $isDemo ? $badge($c->status) : '';

    $placed = $placedAt($order);

    $out = '<section class="rc rc--' . Html::encode($side) . '" aria-label="' . Html::encode($kind) . '">';

    // ---- masthead ----------------------------------------------------------------------------------
    $out .= '<header class="rc__head">'
        . '<div class="rc__kind">' . Html::encode($kind) . '</div>'
        . '<h2 class="rc__store">' . Html::encode($storeName) . '</h2>'
        . '<div class="rc__number">#' . Html::encode((string) $orderNumber) . '</div>'
        . '<div class="rc__date">'
        . ($placed === null
            ? '<span class="rc-muted">No date recorded</span>'
            : Html::encode($placed))
        . '</div></header>';

    // ---- the order's own facts, and the customer's --------------------------------------------------
    // Emitted by both cards or by neither: a note on one side only would be a row the other does not
    // have, and from there every row below it would face the wrong opposite number.
    if (!$comparison->hasOriginal()) {
        $out .= '<div class="rc__absent">'
            . ($isDemo
                ? 'The original is not in the local mirror, so there is nothing to compare against. '
                . 'Everything the trainee entered is shown here in full.'
                : 'This order is not held in the local mirror, so there is nothing to print here.')
            . '</div>';
    }

    foreach ($receipt->sections as $section) {
        if ($section['title'] === 'Totals') {
            continue;
        }

        $out .= '<div class="rc__caption">' . Html::encode($sectionTitle($section['title'])) . '</div>';

        // The payment method is printed under Payment Details with the card, below. It is the same
        // compared field with the same verdict; only where it is printed changes.
        $payment = null;

        foreach ($section['fields'] as $field) {
            if ($section['title'] === 'Order' && $field->label === 'Payment method') {
                $payment = $field;

                continue;
            }

            $out .= '<div class="rc__row" data-rc-status="' . Html::encode($field->status->value) . '">'
                . '<span class="rc__label">' . Html::encode($field->label) . '</span>'
                . '<span class="rc__value">' . $text($pick($field))
                . '<span class="rc__mark">' . $mark($field) . '</span></span></div>';
        }

        if (
            $section['title'] === 'Order'
            && ($payment !== null || $showCardRow || $showExpiryRow || $showZipRow)
        ) {
            $out .= '<div class="rc__caption">Payment Details</div>';

            if ($payment !== null) {
                $out .= '<div class="rc__row" data-rc-status="' . Html::encode($payment->status->value) . '">'
                    . '<span class="rc__label">' . Html::encode($payment->label) . '</span>'
                    . '<span class="rc__value">' . $text($pick($payment))
                    . '<span class="rc__mark">' . $mark($payment) . '</span></span></div>';
            }

            if ($showCardRow) {
                // The last four digits only — the card number itself is never kept, and neither is the
                // verification code, which has no field anywhere in this application.
                $card = PaymentDisplay::card($order);
                $out .= '<div class="rc__row">'
                    . '<span class="rc__label">Card</span>'
                    . '<span class="rc__value">'
                    . ($card === PaymentDisplay::NOT_RECORDED
                        ? '<span class="rc-muted">' . Html::encode($card) . '</span>'
                        : $text($card))
                    . '</span></div>';
            }

            if ($showExpiryRow) {
                $out .= '<div class="rc__row">'
                    . '<span class="rc__label">Expiration date</span>'
                    . '<span class="rc__value">' . $text(PaymentDisplay::expiry($order)) . '</span></div>';
            }

            if ($showZipRow) {
                $out .= '<div class="rc__row">'
                    . '<span class="rc__label">Billing ZIP</span>'
                    . '<span class="rc__value">' . $text(PaymentDisplay::billingZip($order)) . '</span></div>';
            }
        }
    }

    // ---- the lines ----------------------------------------------------------------------------------
    $out .= '<div class="rc__caption">Order Items</div>';

    if ($comparison->items === []) {
        $out .= '<p class="rc__empty rc-muted">'
            . ($comparison->itemsWereCompared() ? 'No line items on this order.' : 'No line items to show.')
            . '</p>';
    } else {
        $out .= '<div class="rc__line rc__line--head">'
            . '<span class="rc__item">Item</span>'
            . '<span class="rc__qty">Qty</span>'
            . '<span class="rc__price">Price</span>'
            . '<span class="rc__total">Total</span></div>';

        foreach ($comparison->items as $item) {
            /** @var ItemComparison $item */
            $mine = $isDemo ? $item->demo : $item->original;
            $modifiers = $modifiersOf($mine);
            // The verdict the score gave the line, so a price-only difference reads as the match it
            // scored — the unit price is shown and never judged. An extra product is keyed apart from
            // an extra field value, so its chip highlights the product lines and nothing else.
            $verdict = ComparisonScorer::itemVerdict($item);
            $status = Html::encode(ReceiptComparison::itemKey($item));

            $out .= '<div class="rc__line rc__line--item' . ($mine === null ? ' rc__line--absent' : '')
                . '" data-rc-status="' . $status . '">'
                . '<span class="rc__item">'
                . ($mine === null
                    ? '<span class="rc-muted">Not on this order</span>'
                    : '<span class="rc__item-name">' . Html::encode($mine->name ?? 'Unnamed item') . '</span>'
                    . ($mine->sn === null ? '' : ' <span class="rc__sn">' . Html::encode($mine->sn) . '</span>'))
                . '<span class="rc__mark">' . ($isDemo ? $badge($verdict, true) : '') . '</span>'
                . '</span>'
                . '<span class="rc__qty">' . $text($mine?->quantityLabel()) . '</span>'
                . '<span class="rc__price">' . $money($mine?->unitPrice) . '</span>'
                . '<span class="rc__total">' . $money($lineTotal($mine)) . '</span></div>';

            // Modifiers and a line note are part of what was ordered, so they stay with their line.
            // Both cards emit the row whenever either side has something, which is what keeps the two
            // documents in step; the side with nothing prints a dash.
            $otherModifiers = $modifiersOf($isDemo ? $item->original : $item->demo);

            if ($modifiers !== [] || $otherModifiers !== []) {
                $out .= '<div class="rc__line rc__line--mods" data-rc-status="' . $status . '">'
                    . '<span class="rc__item">';

                if ($modifiers === []) {
                    $out .= '<span class="rc-muted">-</span>';
                } else {
                    $out .= '<ul class="rc__mods">';

                    foreach ($modifiers as $modifier) {
                        $out .= '<li>' . Html::encode($modifier) . '</li>';
                    }

                    $out .= '</ul>';
                }

                $out .= '</span></div>';
            }

            $note = $mine?->instructions;
            $otherNote = ($isDemo ? $item->original : $item->demo)?->instructions;

            if (!$blank($note) || !$blank($otherNote)) {
                $out .= '<div class="rc__line rc__line--note" data-rc-status="' . $status . '">'
                    . '<span class="rc__item">' . $text($note) . '</span></div>';
            }
        }
    }

    // Lines the provider removed belong to the original and are not expected opposite. The demo card
    // still emits its counterpart so the two stay row for row.
    if ($comparison->removedItems !== []) {
        $out .= '<div class="rc__caption">Removed from the original order</div>';
        $out .= '<div class="rc__removed">';

        if ($isDemo) {
            $out .= '<span class="rc-muted">Removed by the provider, so not expected here.</span>';
        } else {
            $out .= '<ul class="rc__mods">';

            foreach ($comparison->removedItems as $removed) {
                $out .= '<li>' . Html::encode($removed->name ?? 'Unnamed item')
                    . ($removed->quantityLabel() === null
                        ? ''
                        : ' &times;' . Html::encode($removed->quantityLabel()))
                    . '</li>';
            }

            $out .= '</ul>';
        }

        $out .= '</div>';
    }

    // ---- the money ------------------------------------------------------------------------------------
    $out .= '<div class="rc__caption rc__caption--sum">Summary</div>';

    foreach ($receipt->sections as $section) {
        if ($section['title'] !== 'Totals') {
            continue;
        }

        $last = array_key_last($section['fields']);

        foreach ($section['fields'] as $key => $field) {
            $grand = $key === $last ? ' rc__sum--grand' : '';

            // Already inside the total, so printed just above it, where Order58's own receipt prints
            // it — the lines above the total then add up to it. Shown as stored, compared by nothing.
            if ($key === $last && $showFeeRow) {
                $out .= '<div class="rc__sum">'
                    . '<span class="rc__label">Processing fee</span>'
                    . '<span class="rc__amount">' . $money($order?->totals->processingFee) . '</span>'
                    . '<span class="rc__mark"></span></div>';
            }

            // The Summary is for reference: amounts as stored, no verdict, no status for the filter
            // chips to highlight.
            $out .= '<div class="rc__sum' . $grand . '">'
                . '<span class="rc__label">' . Html::encode($field->label) . '</span>'
                . '<span class="rc__amount">' . $money($pick($field)) . '</span>'
                . '<span class="rc__mark"></span></div>';
        }
    }

    $out .= '<footer class="rc__foot">'
        . '<p class="rc__thanks">Thank you.</p>'
        . '<p class="rc__fine">' . Html::encode($kind) . ' #' . Html::encode((string) $orderNumber)
        . '. Training record, not a tax invoice.</p></footer>';

    return $out . '</section>';
};
?>

<div class="page-header rc-screen-only">
    <div>
        <h1 class="page-header__title">Order Receipt Comparison</h1>
        <p class="page-header__subtitle">
            Original #<?= Html::encode((string) $demo->sourceOrderId) ?>
            vs Demo #<?= Html::encode((string) $demo->demoOrderId) ?>
            &middot; <?= Html::encode($storeName) ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= Html::encode($backUrl) ?>">Back to Demo Order</a>
    </div>
</div>

<?php
// The counts and the receipts are the same list read twice, so they cannot drift apart. Each chip is a
// filter over what is already on the page: it dims the rows that do not carry that verdict and changes
// nothing about the orders themselves.
?>
<div class="rc-summary" data-rc-summary>
    <?php foreach ($chips as [$key, $count, $words, $tone]): ?>
        <?php if ($count > 0): ?>
            <button class="btn rc-chip rc-chip--<?= Html::encode($tone) ?>"
                type="button"
                aria-pressed="false"
                data-rc-filter="<?= Html::encode($key) ?>">
                <span class="rc-chip__count"><?= Html::encode((string) $count) ?></span>
                <?= Html::encode($words) ?>
            </button>
        <?php endif; ?>
    <?php endforeach; ?>
    <span class="rc-summary__total rc-muted">
        <?= Html::encode((string) $receipt->total()) ?> compared
    </span>
</div>

<?php if (!$comparison->hasOriginal()): ?>
    <div class="notice notice--warning rc-screen-only">
        <strong>Source order unavailable in the local mirror.</strong>
        Order #<?= Html::encode((string) $demo->sourceOrderId) ?> has not been synced for this store, so
        the left-hand receipt has nothing to print. Everything the trainee entered is on the right.
    </div>
<?php endif; ?>

<div class="rc-pair" data-rc-pair>
    <?= $card('original', 'Original order receipt', $demo->sourceOrderId, $comparison->original) ?>
    <?= $card('demo', 'Demo order receipt', $demo->demoOrderId, $comparison->demo) ?>
</div>