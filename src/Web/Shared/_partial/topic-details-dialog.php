<?php

declare(strict_types=1);

/**
 * The read-only topic details dialog, in one place for both screens that open it.
 *
 * Topic Management and a store's Practice Orders both let a reader click a topic name and see what it
 * actually says. Two copies of this markup would be two places for the dialog's field names to drift
 * from the single handler in `practice.js` that fills them.
 *
 * It lives in the shared web layer rather than in either module because both modules render it, and
 * neither is allowed to name the other. It takes no variables at all: everything it displays is copied
 * in by the script from the attributes of whatever trigger was clicked, which is also why opening it
 * costs no request and exposes no endpoint.
 *
 * **Read-only by construction.** There is no form here, no id and no address — nothing that could
 * change a topic even if something sent it values.
 *
 * @var Yiisoft\View\WebView $this
 */
?>
<dialog class="source-modal practice-dialog" id="practice-topic-details-dialog" data-practice-dialog
        aria-labelledby="practice-details-title">
    <div class="source-modal__head">
        <h2 class="source-modal__title" id="practice-details-title">Topic Details</h2>
        <button class="source-modal__close" type="button" data-practice-dialog-close
                title="Close" aria-label="Close">&times;</button>
    </div>
    <div class="source-modal__body">
        <dl class="practice-details">
            <dt>Topic Name</dt>
            <dd data-practice-details-name></dd>

            <dt>Description</dt>
            <dd class="practice-details__description" data-practice-details-description></dd>

            <dt>Status</dt>
            <dd><span class="badge badge--success" data-practice-details-status></span></dd>

            <?php
            // Shown only where the count is already on the page — Topic Management counts assignments
            // for the whole page in one query, a practice-orders row does not. The script hides the
            // pair rather than printing a number nobody asked the database for.
?>
            <dt data-practice-details-orders-label hidden>Assigned Orders</dt>
            <dd data-practice-details-orders hidden></dd>
        </dl>
        <p class="field__hint" data-practice-details-note hidden></p>
    </div>
    <div class="a2t-confirm__actions">
        <button class="btn btn--sm" type="button" data-practice-dialog-close>Close</button>
    </div>
</dialog>
