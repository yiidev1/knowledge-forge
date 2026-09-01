/*
 * Knowledge Forge — minimal progressive enhancement.
 *
 * Self-hosted and tiny, so the strict `script-src 'self'` content-security policy needs no exceptions.
 * The app is fully usable with JavaScript disabled: destructive actions still confirm via the server
 * form, and chat still works as a normal POST. This script only adds nicer feedback on top:
 *   1. confirmation prompts on destructive actions (data-confirm),
 *   2. a ChatGPT-style "you asked … / assistant is thinking …" state while an answer is generated,
 *   3. auto-scroll of the message list to the newest message, with a "jump to latest" affordance,
 *   4. a dialog showing one cited source, fetched on demand — the server decides what may be shown.
 * Handlers attach by event delegation, not inline attributes, which keeps the CSP free of 'unsafe-inline'.
 */
(function () {
    'use strict';

    var NEAR_BOTTOM_PX = 90;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var submitting = false;

    // Roughly the provider timeout. Past this the request is unusually slow, so the indicator says so
    // instead of implying the page is stuck.
    var SLOW_ANSWER_NOTICE_MS = 45000;

    function messageContainer() {
        return document.querySelector('.chat__messages');
    }

    function isNearBottom(container) {
        return container.scrollHeight - container.scrollTop - container.clientHeight < NEAR_BOTTOM_PX;
    }

    function scrollToBottom(container, smooth) {
        if (!container) {
            return;
        }
        container.scrollTo({ top: container.scrollHeight, behavior: smooth && !reduceMotion ? 'smooth' : 'auto' });
    }

    function hideJump() {
        var jump = document.querySelector('.chat__jump');
        if (jump) {
            jump.setAttribute('hidden', '');
        }
    }

    // ---- Destructive-action confirm + chat submit feedback --------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }

        // Editing a question or retrying its answer also regenerates synchronously; give the submit an
        // optimistic "working" state, then let the browser navigate (PRG) as usual.
        if (form.hasAttribute('data-edit-form')) {
            showEditPending(form);
            return;
        }
        if (form.hasAttribute('data-retry-form')) {
            showRetryPending(form);
            return;
        }

        // If this is a chat form, show the question + a thinking indicator immediately. We do NOT cancel
        // the submit — the browser navigates as usual, and this state stays on screen during the
        // round-trip instead of a blank page with only the tab spinner.
        showChatPending(form);
    });

    function showChatPending(form) {
        var input = form.querySelector('[name="question"]');
        if (!input) {
            return; // not a chat form
        }

        var text = input.value.replace(/\s+$/, '');
        if (text.trim() === '') {
            return; // empty — let the field's native "required" handling take over
        }
        if (submitting) {
            return; // guard against a duplicate submission
        }
        submitting = true;

        var container = messageContainer();
        var thread = document.querySelector('.chat-thread');
        if (!thread) {
            var host = container || form.parentNode;
            var empty = host.querySelector ? host.querySelector('.chat__empty') : null;
            if (empty) {
                empty.remove();
            }
            thread = document.createElement('div');
            thread.className = 'chat-thread';
            host.appendChild(thread);
        }

        thread.appendChild(buildMessage('user', 'You', text));
        thread.appendChild(buildThinking());
        scrollToBottom(container, false);
        hideJump();

        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
            button.textContent = 'Sending…';
        }
        textarea.setAttribute('readonly', 'readonly');

        // A long answer can take a while, and an indefinite "Thinking…" is indistinguishable from a
        // request that died. After the server's own budget has elapsed, say so — the browser is still
        // waiting on the POST, so this only sets expectations; it never cancels or resubmits anything.
        window.setTimeout(function () {
            var label = document.querySelector('.chat-msg--thinking .chat-typing__label');
            if (label) {
                label.textContent = 'Still working — this answer is taking longer than usual…';
            }
        }, SLOW_ANSWER_NOTICE_MS);
    }

    function buildMessage(role, label, text) {
        var msg = el('article', 'chat-msg chat-msg--' + role);
        msg.appendChild(el('div', 'chat-msg__role', label));
        var body = el('div', 'chat-msg__body');
        // textContent only — user input is never treated as HTML.
        text.split('\n').forEach(function (line, index) {
            if (index > 0) {
                body.appendChild(document.createElement('br'));
            }
            body.appendChild(document.createTextNode(line));
        });
        msg.appendChild(body);
        return msg;
    }

    function buildThinking() {
        var msg = el('article', 'chat-msg chat-msg--assistant chat-msg--thinking');
        msg.appendChild(el('div', 'chat-msg__role', 'Assistant'));
        var body = el('div', 'chat-msg__body');
        body.setAttribute('role', 'status');
        var typing = el('span', 'chat-typing');
        typing.appendChild(el('span', 'chat-typing__dot'));
        typing.appendChild(el('span', 'chat-typing__dot'));
        typing.appendChild(el('span', 'chat-typing__dot'));
        body.appendChild(typing);
        body.appendChild(el('span', 'chat-typing__label', 'Thinking…'));
        msg.appendChild(body);
        return msg;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text != null) {
            node.textContent = text;
        }
        return node;
    }

    // ---- Inline question editing + answer retry -------------------------------
    // Reveal/cancel the inline edit form on the latest question, and give the edit/retry submits an
    // optimistic "working" state. Delegated, so the strict CSP needs no inline handlers. The forms POST
    // and navigate (PRG) like the composer, so the page reloads with the regenerated answer; this only
    // sets expectations during the round-trip and never cancels the submit.
    document.addEventListener('click', function (event) {
        var target = event.target;
        // Element, not HTMLElement: a click on the inline <svg>/<path> inside the pencil button is an
        // SVGElement, and would otherwise be ignored — so the centre of the icon wouldn't respond.
        if (!(target instanceof Element)) {
            return;
        }

        var toggle = target.closest('[data-edit-toggle]');
        if (toggle) {
            event.preventDefault();
            toggleEdit(toggle.closest('.chat-msg'), true);
            return;
        }

        var cancel = target.closest('[data-edit-cancel]');
        if (cancel) {
            event.preventDefault();
            toggleEdit(cancel.closest('.chat-msg'), false);
            return;
        }

        // Answer feedback. "Yes", "Change" and "Rate this answer" all just reveal the slider — nothing is
        // recorded until "Save score" posts the form, so moving the slider never writes a score.
        var scoreOpen = target.closest('[data-score-open]');
        if (scoreOpen) {
            event.preventDefault();
            toggleScoring(scoreOpen.closest('.chat-msg'), true);
            return;
        }

        var scoreCancel = target.closest('[data-score-cancel]');
        if (scoreCancel) {
            event.preventDefault();
            toggleScoring(scoreCancel.closest('.chat-msg'), false);
        }
    });

    // Score bands. Mirrors the $band() closure in src/Chat/Web/_partial/score-panel.php, which renders the
    // same words server-side for a saved score — keep the two in step.
    function scoreBand(value) {
        if (value <= 3) { return { slug: 'poor', label: 'Poor' }; }
        if (value <= 6) { return { slug: 'fair', label: 'Fair' }; }
        if (value <= 8) { return { slug: 'good', label: 'Good' }; }
        return { slug: 'excellent', label: 'Excellent' };
    }

    // Live "8/10 · Good" readout beside the slider, plus the band attribute that recolours the track.
    // Display only — the value the server trusts is the posted one, which it re-validates.
    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!(target instanceof Element) || !target.hasAttribute('data-score-range')) {
            return;
        }

        var band = scoreBand(Number(target.value));
        var panel = target.closest('[data-score-panel]');
        if (panel) {
            // Band picks the colour; value positions the track fill. Both are plain attribute swaps, because
            // the CSP forbids inline styles — the CSS holds the fraction for each of the ten stops.
            panel.setAttribute('data-score-band', band.slug);
            panel.setAttribute('data-score-value', target.value);
        }

        var row = target.closest('.chat-msg__score-slider');
        var output = row ? row.querySelector('.chat-msg__score-output') : null;
        if (output) {
            output.textContent = target.value + '/10 · ' + band.label;
        }

        // Leaving the red band clears the note as well as hiding it, so a complaint typed at 2/10 cannot be
        // submitted alongside an 8/10. The server clears it regardless; this just keeps the form honest.
        if (panel && band.slug !== 'poor') {
            var comment = panel.querySelector('[name="feedback_comment"]');
            if (comment) {
                comment.value = '';
            }
        }
    });

    function toggleScoring(article, open) {
        if (!article) {
            return;
        }
        article.classList.toggle('chat-msg--scoring', open);
        if (open) {
            var range = article.querySelector('[data-score-range]');
            if (range) {
                range.focus();
            }
        }
    }

    function toggleEdit(article, open) {
        if (!article) {
            return;
        }
        // A single state class drives visibility (body vs form, and the pencil) from CSS.
        article.classList.toggle('chat-msg--editing', open);
        if (open) {
            var textarea = article.querySelector('textarea[name="content"]');
            if (textarea) {
                textarea.focus();
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            }
        }
    }

    function showEditPending(form) {
        if (form.dataset.submitting === '1') {
            return; // guard against a duplicate submission
        }
        form.dataset.submitting = '1';
        var textarea = form.querySelector('textarea[name="content"]');
        if (textarea) {
            textarea.setAttribute('readonly', 'readonly');
        }
        var cancel = form.querySelector('[data-edit-cancel]');
        if (cancel) {
            cancel.disabled = true;
        }
        setBusy(form.querySelector('button[type="submit"]'), 'Regenerating answer…');
    }

    function showRetryPending(form) {
        if (form.dataset.submitting === '1') {
            return;
        }
        form.dataset.submitting = '1';
        setBusy(form.querySelector('button[type="submit"]'), 'Regenerating answer…');
    }

    function setBusy(button, label) {
        if (button) {
            button.disabled = true;
            button.textContent = label;
        }
    }

    // ---- Copy-to-clipboard for long identifiers ------------------------------
    // Delegated, like every other handler here, so the Content-Security-Policy needs no
    // 'unsafe-inline'. The buttons ship with `hidden` set and are revealed only once this script runs,
    // so a browser without JavaScript never shows a control that could not work — the full identifier
    // is always available in the readonly field inside each row's details panel.
    window.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-copy]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].removeAttribute('hidden');
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        var button = target.closest('button[data-copy]');
        if (!button) {
            return;
        }

        event.preventDefault();

        var value = button.getAttribute('data-copy') || '';
        var original = button.textContent;

        function done(label) {
            button.textContent = label;
            window.setTimeout(function () {
                button.textContent = original;
            }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                done('Copied');
            }, function () {
                done('Press Ctrl+C');
            });
            return;
        }

        done('Press Ctrl+C');
    });

    // ---- Source detail dialog -------------------------------------------------
    // A source chip carries a URL the SERVER built, with its conversation, message and document already
    // bound into it. This code only fetches that URL and prints the reply; it never composes an address and
    // never decides what may be shown — the endpoint re-checks every id and answers 404 when the reader may
    // not see the source. Text is written with textContent, never innerHTML, so a document can never inject
    // markup into the page.
    var sourceRequestId = 0;

    function sourceModal() {
        return document.querySelector('[data-source-modal]');
    }

    function showSourceState(modal, message) {
        var status = modal.querySelector('[data-source-status]');
        var content = modal.querySelector('[data-source-content]');
        var truncated = modal.querySelector('[data-source-truncated]');
        if (status) {
            status.textContent = message;
            status.hidden = false;
        }
        if (content) {
            content.hidden = true;
            content.textContent = '';
        }
        if (truncated) {
            truncated.hidden = true;
        }
    }

    function openSourceModal(modal) {
        if (modal.open) {
            return;
        }
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', 'open'); // very old browsers: still readable, just not modal
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-source-url]') : null;
        if (!trigger) {
            return;
        }
        var modal = sourceModal();
        if (!modal) {
            return; // no dialog on this page — leave the chip inert rather than half-working
        }

        event.preventDefault();

        var title = modal.querySelector('[data-source-title]');
        var meta = modal.querySelector('[data-source-meta]');
        if (title) {
            title.textContent = trigger.textContent.trim() || 'Source';
        }
        if (meta) {
            meta.textContent = '';
        }
        showSourceState(modal, 'Loading…');
        openSourceModal(modal);

        // Only the newest request may paint: clicking two chips quickly must not let the slower reply win.
        sourceRequestId += 1;
        var requestId = sourceRequestId;

        fetch(trigger.getAttribute('data-source-url'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('unavailable');
            }
            return response.json();
        }).then(function (data) {
            if (requestId !== sourceRequestId) {
                return;
            }
            if (title) {
                title.textContent = String(data.title || 'Source');
            }
            if (meta) {
                var parts = [];
                if (data.type) {
                    parts.push(String(data.type));
                }
                if (data.unavailable_reason) {
                    parts.push(String(data.unavailable_reason));
                }
                meta.textContent = parts.join(' · ');
            }

            var status = modal.querySelector('[data-source-status]');
            var content = modal.querySelector('[data-source-content]');
            var truncated = modal.querySelector('[data-source-truncated]');

            if (data.content) {
                if (content) {
                    content.textContent = String(data.content);
                    content.hidden = false;
                }
                if (status) {
                    status.hidden = true;
                }
            } else {
                // Never invent text: a source with no readable body says so.
                showSourceState(modal, 'This source has no readable text to show.');
            }
            if (truncated) {
                truncated.hidden = !data.truncated;
            }
        }).catch(function () {
            if (requestId === sourceRequestId) {
                showSourceState(modal, 'This source is not available.');
            }
        });
    });

    document.addEventListener('click', function (event) {
        var modal = sourceModal();
        if (!modal || !modal.open) {
            return;
        }
        // The close button, or the backdrop — a click landing on the dialog element itself is outside its
        // content box, which is how a native <dialog> reports a backdrop hit.
        if ((event.target.closest && event.target.closest('[data-source-close]')) || event.target === modal) {
            modal.close();
        }
    });

    // ---- Admin chat report: drill-down + detail dialog -------------------------
    // Every trigger is a real <a href> pointing at the report page with the metric's filters applied, so
    // with JavaScript off a click still lands on a usable, correctly filtered read-only view. Here we
    // intercept it and fetch the same filters as JSON instead, which is why the dialog's rows can never
    // disagree with the number that opened it. All text is written with textContent.
    var reportRequestId = 0;
    var reportListUrl = null;

    function reportModal() {
        return document.querySelector('[data-report-modal]');
    }

    function reportEl(modal, name) {
        return modal.querySelector('[data-report-' + name + ']');
    }

    function reportShowStatus(modal, message) {
        var status = reportEl(modal, 'status');
        if (status) {
            status.textContent = message;
            status.hidden = false;
        }
        var list = reportEl(modal, 'list');
        var detail = reportEl(modal, 'detail');
        if (list) { list.hidden = true; }
        if (detail) { detail.hidden = true; }
    }

    function reportOpen(modal) {
        if (modal.open) { return; }
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', 'open');
        }
    }

    function reportDetailUrl(base, extra) {
        var join = base.indexOf('?') === -1 ? '?' : '&';
        return base + join + extra;
    }

    function reportFetch(url, onData, modal) {
        reportRequestId += 1;
        var requestId = reportRequestId;

        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) { throw new Error('unavailable'); }
            return response.json();
        }).then(function (data) {
            if (requestId === reportRequestId) { onData(data); }
        }).catch(function () {
            if (requestId === reportRequestId) {
                reportShowStatus(modal, 'These records could not be loaded.');
            }
        });
    }

    function reportRenderList(modal, data) {
        var body = reportEl(modal, 'rows');
        if (!body) { return; }
        body.textContent = '';

        var rows = data.rows || [];
        if (rows.length === 0) {
            reportShowStatus(modal, 'No records match this metric.');
            return;
        }

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var tr = document.createElement('tr');
            body.appendChild(tr);
            var cells = [row.asked_at, row.agent, row.chat_type, row.store, row.rating, row.status, row.response];
            for (var c = 0; c < cells.length; c++) {
                var td = document.createElement('td');
                td.appendChild(document.createTextNode(String(cells[c] === null ? '—' : cells[c])));
                tr.appendChild(td);
            }
            var actionCell = document.createElement('td');
            var view = document.createElement('button');
            view.type = 'button';
            view.className = 'chat-msg__score-link';
            view.setAttribute('data-report-view', String(row.question_id));
            view.appendChild(document.createTextNode('View'));
            actionCell.appendChild(view);
            tr.appendChild(actionCell);
        }

        var status = reportEl(modal, 'status');
        if (status) { status.hidden = true; }
        var list = reportEl(modal, 'list');
        if (list) { list.hidden = false; }
        var detail = reportEl(modal, 'detail');
        if (detail) { detail.hidden = true; }

        var pager = reportEl(modal, 'pager');
        var info = reportEl(modal, 'pageinfo');
        if (pager && info) {
            var pageCount = Number(data.page_count || 1);
            var page = Number(data.page || 1);
            info.textContent = 'Page ' + page + ' of ' + pageCount + ' · ' + Number(data.total || 0) + ' records';
            pager.hidden = pageCount <= 1;
            var prev = reportEl(modal, 'prev');
            var next = reportEl(modal, 'next');
            if (prev) { prev.disabled = page <= 1; prev.setAttribute('data-page', String(page - 1)); }
            if (next) { next.disabled = page >= pageCount; next.setAttribute('data-page', String(page + 1)); }
        }
    }

    function reportRenderDetail(modal, detail) {
        // Deliberately only the rating: everything else about the record is already in the row that opened
        // this, and repeating it here buries the two things worth reading.
        var facts = reportEl(modal, 'facts');
        if (facts) {
            facts.textContent = '';
            var dt = document.createElement('dt');
            dt.appendChild(document.createTextNode('Rating'));
            var dd = document.createElement('dd');
            dd.appendChild(document.createTextNode(String(detail.rating === null ? '—' : detail.rating)));
            facts.appendChild(dt);
            facts.appendChild(dd);
        }

        var question = reportEl(modal, 'question');
        if (question) { question.textContent = String(detail.question || ''); }

        var answer = reportEl(modal, 'answer');
        if (answer) {
            // Never invent an answer: an unanswered question says so in words.
            answer.textContent = detail.answer === null || detail.answer === undefined
                ? 'No active answer for this question.'
                : String(detail.answer);
        }

        var wrap = reportEl(modal, 'comment-wrap');
        var comment = reportEl(modal, 'comment');
        if (wrap && comment) {
            if (detail.comment) {
                comment.textContent = String(detail.comment);
                wrap.hidden = false;
            } else {
                wrap.hidden = true;
            }
        }

        var back = reportEl(modal, 'back');
        if (back) { back.hidden = reportListUrl === null; }

        var status = reportEl(modal, 'status');
        if (status) { status.hidden = true; }
        var list = reportEl(modal, 'list');
        if (list) { list.hidden = true; }
        var pane = reportEl(modal, 'detail');
        if (pane) { pane.hidden = false; }
    }

    function reportLoadList(modal, url, page) {
        reportListUrl = url;
        reportShowStatus(modal, 'Loading…');
        reportFetch(reportDetailUrl(url, 'dpage=' + Number(page || 1)), function (data) {
            reportRenderList(modal, data);
        }, modal);
    }

    function reportLoadDetail(modal, url, questionId) {
        reportShowStatus(modal, 'Loading…');
        reportFetch(reportDetailUrl(url, 'question=' + Number(questionId)), function (data) {
            if (data.detail) {
                reportRenderDetail(modal, data.detail);
            } else {
                reportShowStatus(modal, 'This record is not available.');
            }
        }, modal);
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest) { return; }
        var modal = reportModal();
        if (!modal) { return; }

        // A metric cell: open the records behind that number.
        var drill = event.target.closest('[data-report-drill]');
        if (drill) {
            event.preventDefault();
            var title = reportEl(modal, 'title');
            var meta = reportEl(modal, 'meta');
            if (title) { title.textContent = drill.getAttribute('data-report-label') || 'Records'; }
            if (meta) { meta.textContent = drill.getAttribute('data-report-context') || ''; }
            reportOpen(modal);
            reportLoadList(modal, drill.getAttribute('data-report-drill'), 1);
            return;
        }

        // A single Q&A row: open that record in full.
        var single = event.target.closest('[data-report-single]');
        if (single) {
            event.preventDefault();
            reportListUrl = null;
            var t = reportEl(modal, 'title');
            var m = reportEl(modal, 'meta');
            if (t) { t.textContent = 'Question detail'; }
            if (m) { m.textContent = ''; }
            reportOpen(modal);
            reportLoadDetail(modal, single.getAttribute('data-report-single'),
                single.getAttribute('data-report-question'));
            return;
        }

        if (!modal.open) { return; }

        var view = event.target.closest('[data-report-view]');
        if (view && reportListUrl) {
            reportLoadDetail(modal, reportListUrl, view.getAttribute('data-report-view'));
            return;
        }

        var back = event.target.closest('[data-report-back]');
        if (back && reportListUrl) {
            reportLoadList(modal, reportListUrl, 1);
            return;
        }

        var pageBtn = event.target.closest('[data-report-prev], [data-report-next]');
        if (pageBtn && reportListUrl && !pageBtn.disabled) {
            reportLoadList(modal, reportListUrl, pageBtn.getAttribute('data-page'));
            return;
        }

        if (event.target.closest('[data-report-close]') || event.target === modal) {
            modal.close();
        }
    });

    // ---- Scroll behaviour: open at newest, toggle "jump to latest" ------------
    window.addEventListener('DOMContentLoaded', function () {
        var container = messageContainer();
        if (!container) {
            return;
        }

        // Open the conversation at the newest message.
        scrollToBottom(container, false);

        var jump = document.querySelector('.chat__jump');

        function updateJump() {
            if (!jump) {
                return;
            }
            if (isNearBottom(container)) {
                jump.setAttribute('hidden', '');
            } else {
                jump.removeAttribute('hidden');
            }
        }

        container.addEventListener('scroll', updateJump, { passive: true });
        if (jump) {
            jump.addEventListener('click', function () {
                scrollToBottom(container, true);
                jump.setAttribute('hidden', '');
            });
        }
        updateJump();

        bindLoadOlder(container);
    });

    /**
     * Cursor-based older history. User message bodies use textContent only; assistant HTML comes from
     * the server Markdown renderer (html_input=escape). Citation filenames use textContent.
     */
    function bindLoadOlder(container) {
        var root = document.querySelector('[data-chat-root]');
        if (!root) {
            return;
        }
        var historyUrl = root.getAttribute('data-history-url');
        if (!historyUrl) {
            return;
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            var button = target.closest('[data-load-older]');
            if (!button || !(button instanceof HTMLButtonElement)) {
                return;
            }
            event.preventDefault();
            if (button.disabled) {
                return;
            }

            var beforeId = button.getAttribute('data-before-id');
            if (!beforeId) {
                return;
            }

            button.disabled = true;
            var url = historyUrl + (historyUrl.indexOf('?') >= 0 ? '&' : '?') + 'before_message_id=' + encodeURIComponent(beforeId);

            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('history ' + response.status);
                }
                return response.json();
            }).then(function (payload) {
                var thread = container.querySelector('[data-chat-thread]');
                if (!thread) {
                    var empty = container.querySelector('[data-chat-empty]');
                    if (empty) {
                        empty.remove();
                    }
                    thread = document.createElement('div');
                    thread.className = 'chat-thread';
                    thread.setAttribute('data-chat-thread', '');
                    container.appendChild(thread);
                }

                var prevHeight = container.scrollHeight;
                var messages = payload.messages || [];
                var frag = document.createDocumentFragment();
                for (var i = 0; i < messages.length; i++) {
                    frag.appendChild(buildHistoryMessage(messages[i]));
                }
                thread.insertBefore(frag, thread.firstChild);
                container.scrollTop = container.scrollHeight - prevHeight;

                var wrap = button.closest('.chat__load-older');
                if (payload.has_older && messages.length > 0) {
                    button.disabled = false;
                    button.setAttribute('data-before-id', String(messages[0].id));
                } else if (wrap) {
                    wrap.remove();
                } else {
                    button.remove();
                }
            }).catch(function () {
                button.disabled = false;
            });
        });
    }

    function buildHistoryMessage(msg) {
        var isUser = msg.role === 'user';
        var article = el('article', 'chat-msg chat-msg--' + (isUser ? 'user' : 'assistant'));
        article.setAttribute('data-message-id', String(msg.id));
        article.appendChild(el('div', 'chat-msg__role', isUser ? 'You' : 'Assistant'));

        var body = el('div', 'chat-msg__body');
        if (isUser) {
            // Never assign untrusted content to innerHTML.
            String(msg.content || '').split('\n').forEach(function (line, index) {
                if (index > 0) {
                    body.appendChild(document.createElement('br'));
                }
                body.appendChild(document.createTextNode(line));
            });
        } else if (msg.html) {
            // Server-rendered Markdown with html_input=escape.
            body.innerHTML = msg.html;
        } else {
            body.appendChild(document.createTextNode(String(msg.content || '')));
        }
        article.appendChild(body);
        article.appendChild(el('div', 'chat-msg__time', String(msg.created_at || '')));

        if (!isUser) {
            var citations = msg.citations || [];
            if (citations.length > 0) {
                var box = el('div', 'chat-msg__citations');
                box.appendChild(el('span', 'chat-msg__citations-label', 'Sources (' + citations.length + ')'));
                for (var c = 0; c < citations.length; c++) {
                    var url = citations[c].source_url;
                    var chip;
                    if (url) {
                        chip = el('button', 'chat-chip chat-chip--source');
                        chip.type = 'button';
                        chip.title = 'View this source';
                        chip.setAttribute('data-source-url', String(url));
                    } else {
                        chip = el('span', 'chat-chip');
                    }
                    chip.appendChild(document.createTextNode(String(citations[c].filename || '')));
                    box.appendChild(chip);
                }
                article.appendChild(box);
            } else if (!msg.is_grounded) {
                var ung = el('div', 'chat-msg__citations util-muted');
                ung.appendChild(document.createTextNode('No cited source for this answer.'));
                article.appendChild(ung);
            }

            // Older answers show a score they already carry, but not the rating control: building the form
            // here would mean templating the action URL and copying the CSRF token into JS. Rating stays on
            // the server-rendered thread, where the form arrives with its own token.
            if (msg.score) {
                var scoreBox = el('div', 'chat-msg__score');
                var saved = el('div', 'chat-msg__score-saved');
                saved.appendChild(el('span', 'chat-msg__score-badge', '✓ Score saved: ' + msg.score + '/10'));
                scoreBox.appendChild(saved);
                article.appendChild(scoreBox);
            }
        }

        return article;
    }
})();

/* ------------------------------------------------------------------------------------------------
 * Audio to Text — job status polling and list refresh.
 *
 * Behaviour lives here rather than in an inline <script> because the application's CSP is
 * `script-src 'self'` with no unsafe-inline: an inline block would silently not run. The templates
 * publish their intent through data attributes and this file reads them.
 *
 * Both behaviours are opt-in per page render. The attributes are emitted only while something can
 * still change, so a completed job and a settled list stop polling permanently — a page left open
 * overnight must not keep asking a question that has already been answered.
 * ---------------------------------------------------------------------------------------------- */
(function () {
    'use strict';

    // The status endpoint returns enum keys, never prose. Mapping them to English here keeps the
    // endpoint incapable of leaking a string that was written for an operator rather than a user.
    var STAGE_LABELS = {
        QUEUED: 'Waiting for the worker',
        CLAIMED: 'Starting',
        CONVERTING: 'Converting audio',
        TRANSCRIBING: 'Transcribing audio',
        DIARIZING: 'Separating speakers',
        MAPPING_SPEAKERS: 'Identifying agent and customer',
        SAVING: 'Saving results',
        COMPLETED: 'Done',
        FAILED: 'Failed'
    };

    var STATUS_LABELS = {
        QUEUED: 'Queued',
        PROCESSING: 'Processing',
        COMPLETED: 'Completed',
        FAILED: 'Failed'
    };

    function startJobPolling(root) {
        var url = root.getAttribute('data-a2t-poll');
        if (!url) {
            return;
        }

        // Re-applied here as well as in the template: two places is cheap, and a stray zero would turn
        // this into a request loop against the application's own server.
        var interval = Math.max(2000, parseInt(root.getAttribute('data-a2t-interval'), 10) || 2000);
        var statusEl = root.querySelector('[data-a2t-field="status"]');
        var stageEl = root.querySelector('[data-a2t-field="stage"]');
        var timer = null;

        function stop() {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        }

        function poll() {
            fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (response) {
                    // A 404, or a redirect to the sign-in page, means there is nothing further to learn
                    // from this endpoint. Stop for good rather than retrying into a wall.
                    if (!response.ok) {
                        stop();
                        return null;
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }

                    if (statusEl && STATUS_LABELS[data.status]) {
                        statusEl.textContent = STATUS_LABELS[data.status];
                        statusEl.className = 'a2t-badge a2t-badge--' + String(data.status).toLowerCase();
                    }

                    if (stageEl) {
                        stageEl.textContent = STAGE_LABELS[data.stage] || '—';
                    }

                    // Terminal: reload once to render the transcript, then never poll again. The
                    // transcript is fetched by that reload rather than sent on every tick.
                    if (data.status === 'COMPLETED' || data.status === 'FAILED') {
                        stop();
                        window.location.reload();
                        return;
                    }

                    timer = setTimeout(poll, interval);
                })
                .catch(function () {
                    // A transient network error is worth one more try; a permanent one will keep
                    // failing harmlessly at this interval.
                    timer = setTimeout(poll, interval);
                });
        }

        timer = setTimeout(poll, interval);
    }

    function startListReload(root) {
        // The list has no single status to watch, and a job submitted in another tab should appear
        // here too, so this reloads the page rather than polling an endpoint.
        var interval = Math.max(2000, parseInt(root.getAttribute('data-a2t-reload'), 10) || 2000);

        setTimeout(function () {
            window.location.reload();
        }, interval);
    }

    function init() {
        var job = document.querySelector('[data-a2t-poll]');
        if (job) {
            startJobPolling(job);
        }

        var list = document.querySelector('[data-a2t-reload]');
        if (list) {
            startListReload(list);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* ---------------------------------------------------------------------------
   Audio to Text — speaker correction.

   Progressive enhancement over the forms that are already on the page. Nothing here builds a request:
   a move fills hidden inputs on a real <form> and submits it, so CSRF, the review_count check and the
   Post/Redirect/Get that follows are identical to every other control in this feature. With the script
   absent the page keeps the plain per-turn forms and stays fully usable.

   Every DOM query is scoped to a data-a2t- attribute, which ModuleIsolationTest enforces.
   --------------------------------------------------------------------------- */
(function () {
    var root = document.querySelector('[data-a2t-review]');
    if (!root) {
        return; // not the correction page — no listeners, no timers
    }

    var dialog = document.querySelector('[data-a2t-move-dialog]');
    var form = document.querySelector('[data-a2t-move-form]');
    if (!dialog || !form) {
        return;
    }

    var mergeDialog = document.querySelector('[data-a2t-merge-dialog]');
    var mergeForm = mergeDialog ? mergeDialog.querySelector('[data-a2t-merge-form]') : null;

    var merge = mergeDialog && mergeForm ? {
        direction: mergeForm.querySelector('[data-a2t-merge-direction]'),
        first: mergeDialog.querySelector('[data-a2t-merge-first]'),
        second: mergeDialog.querySelector('[data-a2t-merge-second]'),
        result: mergeDialog.querySelector('[data-a2t-merge-result]')
    } : null;

    var fields = {
        selection: form.querySelector('[data-a2t-move-selection]'),
        hint: form.querySelector('[data-a2t-move-hint]'),
        role: form.querySelector('[data-a2t-move-role]'),
        preview: dialog.querySelector('[data-a2t-move-preview]'),
        from: dialog.querySelector('[data-a2t-move-from]'),
        to: dialog.querySelector('[data-a2t-move-to]'),
        note: dialog.querySelector('[data-a2t-move-note]')
    };

    // The plain forms live in <noscript>, so a scripting browser never built them — nothing to hide
    // here, only the icon controls to reveal. They are absolutely positioned, so their arrival costs
    // no reflow.
    var tools = root.querySelectorAll('[data-a2t-tools]');
    for (var i = 0; i < tools.length; i++) {
        tools[i].hidden = false;
    }

    function turnOf(el) {
        return el.closest ? el.closest('[data-a2t-turn]') : null;
    }

    /* ----------------------------------------------------------------- inline wording editor */

    function openEditor(turn) {
        var editor = turn.querySelector('[data-a2t-editor]');
        if (!editor) {
            return;
        }
        turn.classList.add('a2t-turn--editing');
        editor.hidden = false;
        var box = editor.querySelector('[data-a2t-editor-text]');
        if (box) {
            box.focus();
            box.setSelectionRange(box.value.length, box.value.length);
        }
    }

    function closeEditor(turn) {
        var editor = turn.querySelector('[data-a2t-editor]');
        if (!editor) {
            return;
        }
        turn.classList.remove('a2t-turn--editing');
        editor.hidden = true;
        var box = editor.querySelector('[data-a2t-editor-text]');
        var original = turn.querySelector('[data-a2t-text]');
        if (box && original) {
            box.value = original.textContent; // discard the draft, matching what Cancel promises
        }
    }

    /* ----------------------------------------------------------------- confirmation */

    // Fills the dialog for a whole-turn move and opens it. Nothing is written until Confirm submits
    // the form, which is a real POST — same CSRF, same review_count check, same redirect.
    function openConfirm(turn) {
        var textEl = turn.querySelector('[data-a2t-text]');
        if (!textEl) {
            return;
        }

        var text = textEl.textContent;
        if (text.replace(/\s+/g, '') === '') {
            return;
        }

        fields.selection.value = text;
        fields.hint.value = '';
        fields.role.value = turn.getAttribute('data-a2t-target-role') || '';
        form.setAttribute('action', turn.getAttribute('data-a2t-move-url') || '');

        fields.preview.textContent = text;
        fields.from.textContent = turn.getAttribute('data-a2t-label') || '';
        fields.to.textContent = turn.getAttribute('data-a2t-target-label') || '';

        var merges = turn.getAttribute('data-a2t-merges') === '1';
        fields.note.hidden = !merges;
        if (merges) {
            fields.note.textContent = 'This turn will be joined with the neighbouring turn beside it, '
                + 'because they will then be the same speaker and the same role.';
        }

        turn.classList.add('a2t-turn--moving');

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    function endMove() {
        var moving = root.querySelectorAll('[data-a2t-turn].a2t-turn--moving');
        for (var i = 0; i < moving.length; i++) {
            moving[i].classList.remove('a2t-turn--moving');
        }
        if (typeof dialog.close === 'function' && dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    /* ----------------------------------------------------------------- drag to the other role */

    // Pointer events rather than HTML5 drag-and-drop: one path covers mouse, pen and touch, where
    // dragstart/drop would simply never fire on a phone.
    var drag = null;
    var zone = null;

    function dropZone() {
        if (zone) {
            return zone;
        }
        zone = document.createElement('div');
        zone.className = 'a2t-dropzone';
        zone.setAttribute('data-a2t-dropzone', '');
        zone.hidden = true;
        // The label is its own chip, pinned near the top: centred in the band it would sit on top of
        // whatever message happens to be there.
        zone.appendChild(document.createElement('span'));
        zone.firstChild.className = 'a2t-dropzone__label';
        document.body.appendChild(zone);

        return zone;
    }

    // The drop target is a *side*, never another bubble: the conversation's order is fixed, so a turn
    // can only change whose it is, not when it was said.
    function placeZone(turn) {
        var scroller = root.querySelector('[data-a2t-scroll]');
        if (!scroller) {
            return null;
        }

        var box = scroller.getBoundingClientRect();
        var toAgent = turn.getAttribute('data-a2t-target-role') === 'AGENT';
        var half = box.width / 2;
        var el = dropZone();

        el.style.top = box.top + 'px';
        el.style.height = box.height + 'px';
        el.style.left = (toAgent ? box.left + half : box.left) + 'px';
        el.style.width = half + 'px';
        el.firstChild.textContent = 'Move to ' + (turn.getAttribute('data-a2t-target-label') || '');
        el.hidden = false;
        el.classList.remove('a2t-dropzone--over');

        return el;
    }

    /**
     * The turns a drag may legitimately land on, and what each one would do.
     *
     * Only the two immediate neighbours are candidates, and only in the same lane: merging is the one
     * same-lane operation the domain has, and it joins a turn to the turn *beside* it. Anything else
     * in the same lane is marked not-allowed rather than left inert, so a refusal is visible instead
     * of feeling like a dead drop.
     */
    function markTargets(turn) {
        var index = parseInt(turn.getAttribute('data-a2t-turn'), 10);
        var role = turn.getAttribute('data-a2t-role');
        var all = root.querySelectorAll('[data-a2t-turn]');

        for (var i = 0; i < all.length; i++) {
            var other = all[i];
            if (other === turn) {
                continue;
            }

            var otherIndex = parseInt(other.getAttribute('data-a2t-turn'), 10);

            // The opposite lane is the move band's business, not a per-bubble target.
            if (other.getAttribute('data-a2t-role') !== role) {
                continue;
            }

            if (otherIndex === index - 1 || otherIndex === index + 1) {
                var verdict = turn.getAttribute(
                    otherIndex === index - 1 ? 'data-a2t-merge-prev' : 'data-a2t-merge-next',
                );

                if (verdict === 'ok') {
                    other.classList.add('a2t-turn--droppable');
                    other.setAttribute('data-a2t-drop-hint', 'Merge with this message');
                } else if (verdict) {
                    // Same lane, adjacent, but the diarizer heard two different voices.
                    other.classList.add('a2t-turn--refused');
                    other.setAttribute('data-a2t-drop-hint', verdict);
                }

                continue;
            }

            other.classList.add('a2t-turn--invalid');
            other.setAttribute('data-a2t-drop-hint', 'Turns cannot be reordered — the conversation keeps the order it was spoken in.');
        }
    }

    function clearTargets() {
        var all = root.querySelectorAll('[data-a2t-turn]');
        for (var i = 0; i < all.length; i++) {
            all[i].classList.remove('a2t-turn--droppable', 'a2t-turn--refused', 'a2t-turn--invalid', 'a2t-turn--over');
            all[i].removeAttribute('data-a2t-drop-hint');
        }
    }

    // Which turn the pointer is over, if any. The band has pointer-events: none, so it never masks
    // a bubble underneath it.
    function turnUnder(event) {
        var el = document.elementFromPoint(event.clientX, event.clientY);

        return el && el.closest ? el.closest('[data-a2t-turn]') : null;
    }

    /**
     * A same-lane turn the drag has something to say about — mergeable, refused, or simply not a
     * destination. Only these pre-empt the move band; an opposite-lane bubble sitting inside the band
     * is just scenery, and dropping on it means the same as dropping beside it.
     */
    function laneTargetUnder(event) {
        var over = turnUnder(event);

        if (!over || !drag || over === drag.turn) {
            return null;
        }

        // A turn row spans the full width of the thread even though its bubble hugs one side, so
        // elementFromPoint alone would report a left-hand turn for a pointer way over on the right.
        // The bubble is the thing on screen, so the bubble is the thing that has to be under it.
        var bubble = over.querySelector('.a2t-bubble');
        if (!bubble) {
            return null;
        }

        var box = bubble.getBoundingClientRect();
        if (event.clientX < box.left || event.clientX > box.right
            || event.clientY < box.top || event.clientY > box.bottom) {
            return null;
        }

        return over.classList.contains('a2t-turn--droppable')
            || over.classList.contains('a2t-turn--refused')
            || over.classList.contains('a2t-turn--invalid')
            ? over
            : null;
    }

    function overZone(event) {
        if (!zone || zone.hidden) {
            return false;
        }
        var box = zone.getBoundingClientRect();

        return event.clientX >= box.left && event.clientX <= box.right
            && event.clientY >= box.top && event.clientY <= box.bottom;
    }

    function stopDrag() {
        if (drag && drag.turn) {
            drag.turn.classList.remove('a2t-turn--dragging');
        }
        root.classList.remove('a2t-review--dragging');
        if (zone) {
            zone.hidden = true;
            zone.classList.remove('a2t-dropzone--over');
        }
        clearTargets();
        drag = null;
    }

    function openMergeConfirm(turn, target) {
        if (!merge) {
            return;
        }

        var index = parseInt(turn.getAttribute('data-a2t-turn'), 10);
        var targetIndex = parseInt(target.getAttribute('data-a2t-turn'), 10);
        var before = targetIndex < index;

        var dragged = turn.getAttribute('data-a2t-text-value') || '';
        var neighbour = target.getAttribute('data-a2t-text-value') || '';

        merge.direction.value = before ? 'previous' : 'next';
        mergeForm.setAttribute('action', turn.getAttribute('data-a2t-merge-url') || '');

        // Shown in the order they will be joined, which is the order they were spoken.
        merge.first.textContent = before ? neighbour : dragged;
        merge.second.textContent = before ? dragged : neighbour;
        merge.result.textContent = merge.first.textContent + ' ' + merge.second.textContent;

        turn.classList.add('a2t-turn--moving');
        target.classList.add('a2t-turn--moving');

        if (typeof mergeDialog.showModal === 'function') {
            mergeDialog.showModal();
        } else {
            mergeDialog.setAttribute('open', 'open');
        }
    }

    document.addEventListener('pointerdown', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var grip = target.closest('[data-a2t-grip]');
        if (!grip) {
            return;
        }

        event.preventDefault();
        drag = { turn: turnOf(grip), grip: grip, x: event.clientX, y: event.clientY, active: false };

        if (grip.setPointerCapture) {
            grip.setPointerCapture(event.pointerId);
        }
    });

    document.addEventListener('pointermove', function (event) {
        if (!drag) {
            return;
        }

        // A few pixels of travel before anything happens, so a stray tap on the handle is not a drag.
        if (!drag.active) {
            if (Math.abs(event.clientX - drag.x) + Math.abs(event.clientY - drag.y) < 5) {
                return;
            }
            drag.active = true;
            drag.turn.classList.add('a2t-turn--dragging');
            root.classList.add('a2t-review--dragging');
            placeZone(drag.turn);
            markTargets(drag.turn);
        }

        var lane = laneTargetUnder(event);
        var all = root.querySelectorAll('[data-a2t-turn]');
        for (var i = 0; i < all.length; i++) {
            all[i].classList.toggle('a2t-turn--over', all[i] === lane);
        }

        if (zone) {
            zone.classList.toggle('a2t-dropzone--over', !lane && overZone(event));
        }
    });

    document.addEventListener('pointerup', function (event) {
        if (!drag) {
            return;
        }

        var turn = drag.turn;
        var lane = drag.active ? laneTargetUnder(event) : null;
        var mergeTarget = lane && lane.classList.contains('a2t-turn--droppable') ? lane : null;
        var moved = drag.active && !lane && overZone(event);

        stopDrag();

        if (mergeTarget) {
            openMergeConfirm(turn, mergeTarget);
        } else if (moved) {
            openConfirm(turn);
        }
        // Anything else — a refused neighbour, a non-adjacent turn, or empty space — does nothing,
        // having already said so while the pointer was over it.
    });

    document.addEventListener('pointercancel', stopDrag);

    /* ----------------------------------------------------------------- wiring */

    document.addEventListener('click', function (event) {
        var target = event.target;
        // Element rather than HTMLElement: a click on the inline <svg> inside an icon button is an
        // SVGElement and would otherwise be ignored, so the middle of the icon would not respond.
        if (!(target instanceof Element)) {
            return;
        }

        var edit = target.closest('[data-a2t-edit]');
        if (edit) {
            event.preventDefault();
            openEditor(turnOf(edit));
            return;
        }

        var cancelEdit = target.closest('[data-a2t-edit-cancel]');
        if (cancelEdit) {
            event.preventDefault();
            closeEditor(turnOf(cancelEdit));
            return;
        }

        if (target.closest('[data-a2t-move-cancel]')) {
            event.preventDefault();
            endMove();

            return;
        }

        if (target.closest('[data-a2t-merge-cancel]')) {
            event.preventDefault();
            endMerge();
        }
    });

    function endMerge() {
        var moving = root.querySelectorAll('[data-a2t-turn].a2t-turn--moving');
        for (var i = 0; i < moving.length; i++) {
            moving[i].classList.remove('a2t-turn--moving');
        }
        if (mergeDialog) {
            if (typeof mergeDialog.close === 'function' && mergeDialog.open) {
                mergeDialog.close();
            } else {
                mergeDialog.removeAttribute('open');
            }
        }
    }

    if (mergeDialog) {
        mergeDialog.addEventListener('cancel', endMerge);
    }

    if (mergeForm) {
        mergeForm.addEventListener('submit', function () {
            var confirm = mergeForm.querySelector('[data-a2t-merge-confirm]');
            if (confirm) {
                confirm.disabled = true;
                confirm.textContent = 'Merging…';
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drag) {
            stopDrag();
        }
    });

    // Escape closes the dialog the same way Cancel does, so the highlight is cleared either way.
    dialog.addEventListener('cancel', function () {
        endMove();
    });

    // One submission per confirmation. Without this a double click sends the move twice; the second
    // would lose the version check and flash a conflict, which is correct but alarming.
    form.addEventListener('submit', function () {
        var confirm = form.querySelector('[data-a2t-move-confirm]');
        if (confirm) {
            confirm.disabled = true;
            confirm.textContent = 'Moving…';
        }
    });

    var editors = root.querySelectorAll('[data-a2t-editor]');
    for (var e = 0; e < editors.length; e++) {
        editors[e].addEventListener('submit', function (event) {
            var save = event.currentTarget.querySelector('[data-a2t-edit-save]');
            if (save) {
                save.disabled = true;
            }
        });
    }
}());

/* ---------------------------------------------------------------------------
   Audio to Text — conversation scrolling.

   Shared by the conversation page and the correction page, both of which render the same thread into
   a .a2t-chat__scroll container. Two jobs: open at the newest turn with a "jump to latest" pill, and
   hold back the older turns until they are asked for.

   The hold-back is presentation only. Every turn is in the markup the server sent — a recording is
   capped at five minutes, so a conversation is bounded and there is nothing to fetch — and hiding the
   older ones simply spares the browser laying out several hundred bubbles nobody has scrolled to yet.
   Without this script they are all visible, which is the correct fallback rather than a degraded one.
   --------------------------------------------------------------------------- */
(function () {
    var WINDOW = 20; // turns shown initially, and revealed per click

    var container = document.querySelector('[data-a2t-scroll]');
    if (!container) {
        return; // not one of the two chat pages
    }

    var thread = container.querySelector('.a2t-thread');
    var jump = document.querySelector('[data-a2t-jump]');

    function turns() {
        return thread ? thread.querySelectorAll('.a2t-turn') : [];
    }

    function toBottom(smooth) {
        container.scrollTo
            ? container.scrollTo({ top: container.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
            : (container.scrollTop = container.scrollHeight);
    }

    function nearBottom() {
        return container.scrollHeight - container.scrollTop - container.clientHeight < 60;
    }

    function updateJump() {
        if (!jump) {
            return;
        }
        if (nearBottom()) {
            jump.setAttribute('hidden', '');
        } else {
            jump.removeAttribute('hidden');
        }
    }

    /* ----------------------------------------------------------------- older turns */

    var hiddenTurns = [];
    var button = null;

    function label() {
        var count = hiddenTurns.length;
        var next = count < WINDOW ? count : WINDOW;

        return '↑ Show ' + next + ' earlier message' + (next === 1 ? '' : 's')
            + ' (' + count + ' hidden)';
    }

    function reveal() {
        // Keep the reader where they are: revealing above them would otherwise shove the turn they
        // were reading down the page by however tall the new ones are.
        var anchor = container.scrollHeight - container.scrollTop;

        for (var i = 0; i < WINDOW && hiddenTurns.length > 0; i++) {
            hiddenTurns.pop().hidden = false;
        }

        if (hiddenTurns.length === 0) {
            if (button) {
                button.parentNode.removeChild(button);
                button = null;
            }
        } else if (button) {
            button.textContent = label();
        }

        container.scrollTop = container.scrollHeight - anchor;
    }

    function holdBackOlder() {
        var all = turns();
        if (all.length <= WINDOW || !thread) {
            return;
        }

        for (var i = 0; i < all.length - WINDOW; i++) {
            all[i].hidden = true;
            hiddenTurns.push(all[i]);
        }

        button = document.createElement('button');
        button.type = 'button';
        button.className = 'a2t-thread__earlier';
        button.textContent = label();
        button.addEventListener('click', reveal);
        thread.insertBefore(button, thread.firstChild);
    }

    holdBackOlder();
    toBottom(false);
    updateJump();

    container.addEventListener('scroll', updateJump, { passive: true });

    if (jump) {
        jump.addEventListener('click', function () {
            toBottom(true);
            jump.setAttribute('hidden', '');
        });
    }
}());
