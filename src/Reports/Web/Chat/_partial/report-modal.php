<?php

declare(strict_types=1);

/**
 * The report's one read-only detail dialog, rendered empty once per page.
 *
 * A single dialog serves both jobs — the list behind a metric, and one question's full detail — because
 * nesting dialogs is worse than swapping the contents of one. `admin.js` toggles which of the two panes is
 * visible and fills them in with `textContent`.
 *
 * Native `<dialog>` is used deliberately: Escape, the backdrop and the focus trap come from the browser
 * rather than from hand-written key handling. Nothing sensitive is printed here; this is only the shell the
 * server's reply is poured into, and every trigger carries a URL the *server* built.
 *
 * @var Yiisoft\View\WebView $this
 */
?>
<dialog class="source-modal report-modal" data-report-modal aria-labelledby="report-modal-title">
    <div class="source-modal__head">
        <div>
            <h2 class="source-modal__title" id="report-modal-title" data-report-title>Details</h2>
            <p class="source-modal__meta" data-report-meta></p>
        </div>
        <button type="button" class="source-modal__close" data-report-close
                title="Close" aria-label="Close">&times;</button>
    </div>

    <div class="source-modal__body">
        <p class="source-modal__status" data-report-status>Loading…</p>

        <!-- List pane: the records behind a metric. -->
        <div data-report-list hidden>
            <div class="table-wrap">
                <table class="table report-modal__table">
                    <thead>
                        <tr>
                            <th>Asked</th>
                            <th>Agent</th>
                            <th>Type</th>
                            <th>Store</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Response</th>
                            <th><span class="util-visually-hidden">Detail</span></th>
                        </tr>
                    </thead>
                    <tbody data-report-rows></tbody>
                </table>
            </div>
            <div class="report-modal__pager" data-report-pager hidden>
                <button type="button" class="btn btn--secondary btn--sm" data-report-prev>← Previous</button>
                <span class="util-muted" data-report-pageinfo></span>
                <button type="button" class="btn btn--secondary btn--sm" data-report-next>Next →</button>
            </div>
        </div>

        <!-- Detail pane: one question and the answer that currently stands for it. -->
        <div data-report-detail hidden>
            <button type="button" class="chat-msg__score-link report-modal__back" data-report-back hidden>
                ← Back to list
            </button>
            <dl class="report-modal__facts" data-report-facts></dl>

            <h3 class="report-modal__section">Question</h3>
            <pre class="source-modal__content" data-report-question></pre>

            <h3 class="report-modal__section">Answer</h3>
            <pre class="source-modal__content" data-report-answer></pre>

            <div data-report-comment-wrap hidden>
                <h3 class="report-modal__section">Feedback comment</h3>
                <pre class="source-modal__content" data-report-comment></pre>
            </div>
        </div>
    </div>
</dialog>
