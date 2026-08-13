<?php

declare(strict_types=1);

/**
 * The source-detail dialog, shared by all four chat surfaces.
 *
 * Rendered once per chat page and left empty; clicking a source chip fetches that citation's detail from the
 * server and `admin.js` fills these nodes in. Native `<dialog>` is used deliberately — Escape, the backdrop
 * and the focus trap come from the browser rather than from hand-written key handling, and with JavaScript
 * off nothing here is reachable, which is correct: there is no content to leak.
 *
 * No ids of any kind are printed here. The chip carries a server-generated URL; this markup carries only the
 * shell the response is poured into, always via `textContent`, never as HTML.
 *
 * @var Yiisoft\View\WebView $this
 */
?>
<dialog class="source-modal" data-source-modal aria-labelledby="source-modal-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="source-modal-title" data-source-title>Source</h2>
            <p class="source-modal__meta" data-source-meta></p>
        </div>
        <button type="button" class="source-modal__close" data-source-close
                title="Close" aria-label="Close">&times;</button>
    </div>

    <div class="source-modal__body">
        <p class="source-modal__status" data-source-status>Loading…</p>
        <pre class="source-modal__content" data-source-content hidden></pre>
        <p class="source-modal__truncated" data-source-truncated hidden>
            This source is longer than shown here.
        </p>
    </div>
</dialog>
