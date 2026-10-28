<?php

declare(strict_types=1);

use Yiisoft\Html\Html;

/**
 * "Sync Order58 Case Orders" — the one trigger, on every page that offers it.
 *
 * Confirmed before it is made, because this click contacts the provider. A link rather than a button,
 * and the page answers `?confirm-case-orders=1` by rendering the dialog already open — the same
 * arrangement the transcription settings dialog uses, and it is what makes the feature work with no
 * script at all. `order58-case-orders.js` upgrades the click to `showModal()`.
 *
 * When it cannot run it is plain text rather than a dead control: there is nothing to click, and the
 * `title` says why. A disabled-looking link that still navigated would be worse than either.
 *
 * @var string|null $caseOrderBlockedReason the coordinator's own sentence, or null when a press may start
 * @var string $confirmUrl the rendering page with `confirm-case-orders=1` added to what it already holds
 * @var string $buttonClass the page's own button style, so the control fits each header
 */
?>
<?php if ($caseOrderBlockedReason === null): ?>
    <a class="<?= Html::encode($buttonClass) ?>" href="<?= Html::encode($confirmUrl) ?>"
       data-o58-case-order-open>Sync Order58 Case Orders</a>
<?php else: ?>
    <span class="btn btn--secondary" aria-disabled="true"
          title="<?= Html::encode($caseOrderBlockedReason) ?>">Sync Order58 Case Orders</span>
<?php endif; ?>
