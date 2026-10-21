/*
 * Topic Management and Practice Orders: dialogs, confirms, and the bulk-selection toolbar.
 *
 * Everything here is an upgrade on markup that already works without it. The dialogs are rendered
 * `open` by the server when the URL asks for them, every trigger is a real link, and every destructive
 * button is a real form. This file makes those feel modern; it is never what makes them function.
 *
 * Bound by delegation rather than inline handlers, because this application sends `script-src 'self'`
 * and an inline `onclick` would simply be refused.
 */
(function () {
    'use strict';

    var canDialog = typeof window.HTMLDialogElement === 'function';

    function dialogById(id) {
        var el = id ? document.getElementById(id) : null;

        return el !== null && el.hasAttribute('data-practice-dialog') ? el : null;
    }

    function open(dialog) {
        if (dialog === null || !canDialog || typeof dialog.showModal !== 'function') {
            return false;
        }

        // The server may already have rendered it open as the no-JavaScript fallback. Close that
        // first, or showModal() throws and the backdrop never appears.
        if (dialog.open && !dialog.matches(':modal')) {
            dialog.close();
        }

        if (!dialog.open) {
            dialog.showModal();
        }

        return true;
    }

    // A server-rendered open dialog is upgraded to a real modal on load, so it gets a backdrop and
    // traps focus like every other one.
    document.addEventListener('DOMContentLoaded', function () {
        var preopened = document.querySelectorAll('[data-practice-dialog][open]');

        for (var i = 0; i < preopened.length; i++) {
            open(preopened[i]);
        }
    });

    // The dialog on the page is already filled in for exactly one thing — a new topic, or one
    // particular existing topic — because the server rendered it. A trigger that wants a different one
    // must let the browser follow its link so the server can render that one; opening what is already
    // here would show the Add form under an "Edit" click, or one topic's values under another's name.
    function wants(opener, dialog) {
        var asked = opener.getAttribute('data-practice-dialog-for');

        return asked === null || asked === dialog.getAttribute('data-practice-dialog-mode');
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-practice-dialog-open]');

        if (opener !== null) {
            var target = dialogById(opener.getAttribute('data-practice-dialog-open'));

            // Only swallow the click if we actually opened the right dialog; otherwise let the link
            // navigate, which is the fallback and, for Edit, the correct path.
            if (target !== null && wants(opener, target) && open(target)) {
                event.preventDefault();
            }

            return;
        }

        var closer = event.target.closest('[data-practice-dialog-close]');

        if (closer !== null) {
            var owning = closer.closest('[data-practice-dialog]');

            if (owning !== null && owning.open) {
                event.preventDefault();
                owning.close();
            }

            return;
        }

        // Clicking the backdrop of a confirmation is a dismissal like any other.

        // Clicking the backdrop is a click on the dialog element itself.
        if (event.target.matches('[data-practice-dialog]')) {
            event.target.close();
        }
    });

    // Destructive-looking actions confirm with a sentence that says what will happen, not "are you
    // sure?". The text comes from the server so the count in it is the real one.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-practice-confirm]');

        if (form !== null && !window.confirm(form.getAttribute('data-practice-confirm'))) {
            event.preventDefault();
        }
    });

    // ---- Practice Orders: selection and the assignment dialog -------------------------------------
    //
    // The checkboxes belong to the bulk form by `form=`, so the browser submits them without any help
    // from here. All this does is count them, show the toolbar once there is something to apply, and
    // point the single-order dialog at the row that opened it.

    function bulkForm() {
        return document.querySelector('[data-practice-bulk]');
    }

    function selected() {
        return document.querySelectorAll('[data-practice-select]:checked');
    }

    function refreshSelection() {
        var form = bulkForm();

        if (form === null) {
            return;
        }

        var count = selected().length;
        var label = form.querySelector('[data-practice-count]');

        if (label !== null) {
            label.textContent = count + ' selected';
        }

        // `hidden` rather than a disabled button: there is nothing to do until a row is chosen, and
        // saying so with an empty toolbar would be saying it twice.
        form.hidden = count === 0;

        var all = document.querySelector('[data-practice-select-all]');

        if (all !== null) {
            var boxes = document.querySelectorAll('[data-practice-select]');
            all.checked = boxes.length > 0 && count === boxes.length;
            // Some chosen but not all: neither ticked nor empty is the honest state.
            all.indeterminate = count > 0 && count < boxes.length;
        }
    }

    // Nothing here listens to the topic filter's select. Choosing an option must change only what the
    // control shows; the list changes when Apply is pressed, and not before. A select that navigates
    // on change loses a reader who is still looking at the options, and makes the Apply button a lie.

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-practice-bulk-topic]')) {
            refreshBulkTopic();

            return;
        }

        if (event.target.matches('[data-practice-select-all]')) {
            var boxes = document.querySelectorAll('[data-practice-select]');

            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = event.target.checked;
            }

            refreshSelection();

            return;
        }

        if (event.target.matches('[data-practice-select]')) {
            refreshSelection();
        }
    });

    // ---- Order Testing: assigning a topic, with a confirmation first -----------------------------
    //
    // The control is the select in the row. Choosing a different topic does NOT save: it opens a
    // confirmation naming the order, what it carries now and what it would carry, and only Confirm
    // submits the row's own form. Cancel puts the select back to what is actually stored — a control
    // left showing a choice the database does not hold is a page lying about its own state.
    //
    // With no script at all, the Save button beside each select submits the same form directly. That
    // is why this hides those buttons rather than the markup omitting them: the fallback is real.

    var pendingForm = null;

    function savedValueOf(select) {
        return select.getAttribute('data-practice-saved') || '';
    }

    function labelOf(select, value) {
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === value) {
                return select.options[i].textContent.trim();
            }
        }

        return '';
    }

    function restorePending() {
        if (pendingForm === null) {
            return;
        }

        var select = pendingForm.querySelector('[data-practice-assign-select]');

        if (select !== null) {
            select.value = savedValueOf(select);
        }

        pendingForm = null;
    }

    // Every inline Save is redundant once this file is running, so it is taken away rather than left
    // as a second way to do the same thing without the confirmation.
    document.addEventListener('DOMContentLoaded', function () {
        var saves = document.querySelectorAll('[data-practice-assign-save]');

        for (var i = 0; i < saves.length; i++) {
            saves[i].hidden = true;
        }

        var bulk = document.querySelector('[data-practice-bulk-submit]');

        if (bulk !== null) {
            bulk.setAttribute('type', 'button');
        }

        refreshBulkTopic();
    });

    /**
     * The bulk button follows the bulk choice.
     *
     * "Not Selected" is the absence of a choice, not an instruction to unassign, so there is nothing to
     * confirm and nothing to send while it is showing. The button is disabled rather than hidden: it
     * says what is missing, where a vanished button would say nothing at all.
     */
    function refreshBulkTopic() {
        var topic = document.querySelector('[data-practice-bulk-topic]');
        var submit = document.querySelector('[data-practice-bulk-submit]');

        if (topic === null || submit === null) {
            return;
        }

        submit.disabled = topic.value === '';
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-practice-assign-select]')) {
            return;
        }

        var select = event.target;
        var form = select.closest('[data-practice-assign-row]');
        var dialog = dialogById('practice-confirm-dialog');

        if (form === null || dialog === null) {
            return;
        }

        var saved = savedValueOf(select);

        if (select.value === saved) {
            // Back to what is already stored. Nothing to confirm and nothing to save.
            return;
        }

        pendingForm = form;

        var order = form.getAttribute('data-practice-order') || '';
        var current = form.getAttribute('data-practice-current-label') || '';
        var next = select.value === '' ? '' : labelOf(select, select.value);

        var orderCell = dialog.querySelector('[data-practice-confirm-order]');
        var currentCell = dialog.querySelector('[data-practice-confirm-current]');
        var nextCell = dialog.querySelector('[data-practice-confirm-next]');
        var note = dialog.querySelector('[data-practice-confirm-note]');

        if (orderCell !== null) {
            orderCell.textContent = '#' + order;
        }

        if (currentCell !== null) {
            currentCell.textContent = current === '' ? 'Not assigned' : current;
            currentCell.classList.toggle('util-muted', current === '');
        }

        if (nextCell !== null) {
            nextCell.textContent = next === '' ? 'Not assigned' : next;
            nextCell.classList.toggle('util-muted', next === '');
        }

        if (note !== null) {
            note.textContent = select.value === ''
                ? 'This removes the topic from the order. The order keeps everything else.'
                : 'The order keeps every recording and demo order it already has.';
        }

        if (!open(dialog)) {
            // No dialog support: let the form be submitted the ordinary way instead of silently
            // doing nothing with a selection the reader has already made.
            form.submit();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-practice-confirm-submit]') === null) {
            return;
        }

        var form = pendingForm;

        // Cleared first, so a double click cannot submit twice: the second finds nothing pending.
        pendingForm = null;

        if (form === null) {
            return;
        }

        var submit = form.querySelector('[data-practice-assign-save]');

        if (submit !== null) {
            submit.disabled = true;
        }

        form.submit();
    });

    // ---- Order Testing: the bulk confirmation ----------------------------------------------------

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-practice-bulk-submit]') === null) {
            return;
        }

        var form = bulkForm();
        var dialog = dialogById('practice-bulk-confirm-dialog');

        if (form === null) {
            return;
        }

        var count = selected().length;
        var chosen = form.querySelector('[data-practice-bulk-topic]');

        if (count === 0 || chosen === null || chosen.value === '') {
            // Nothing selected, or no topic chosen. Either way there is nothing to confirm — and
            // "Not Selected" must never be read as "take their topics away".
            return;
        }

        if (dialog === null) {
            form.submit();

            return;
        }

        var topic = form.querySelector('#practice-bulk-topic');
        var countCell = dialog.querySelector('[data-practice-bulk-confirm-count]');
        var topicCell = dialog.querySelector('[data-practice-bulk-confirm-topic]');

        if (countCell !== null) {
            countCell.textContent = count + (count === 1 ? ' order' : ' orders');
        }

        if (topicCell !== null && topic !== null) {
            topicCell.textContent = topic.options[topic.selectedIndex].textContent.trim();
        }

        if (!open(dialog)) {
            form.submit();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-practice-bulk-confirm-submit]') === null) {
            return;
        }

        var form = bulkForm();

        if (form !== null) {
            event.target.disabled = true;
            form.submit();
        }
    });

    // ---- The topic filter: open, tick, and apply when asked --------------------------------------
    //
    // Nothing here applies anything. The panel is a `<details>`, the choices are checkboxes, and the
    // list changes when Apply is pressed — which is the whole point of the control. All this adds is
    // the running summary and the search box, neither of which changes what is submitted.

    function refreshFilterSummary() {
        var picker = document.querySelector('[data-practice-filter-picker]');

        if (picker === null) {
            return;
        }

        var summary = picker.querySelector('[data-practice-filter-summary]');
        var chosen = picker.querySelectorAll('[data-practice-filter-choice]:checked');

        if (summary !== null) {
            summary.textContent = chosen.length === 0 ? 'All topics' : chosen.length + ' selected';
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-practice-filter-choice]')) {
            refreshFilterSummary();
        }
    });

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-practice-filter-search]')) {
            return;
        }

        var needle = event.target.value.trim().toLowerCase();
        var rows = document.querySelectorAll('[data-practice-filter-row]');

        for (var i = 0; i < rows.length; i++) {
            var text = rows[i].textContent.toLowerCase();
            // A ticked choice stays visible however the search narrows, so a reader cannot lose track
            // of something they have already chosen.
            var ticked = rows[i].querySelector('[data-practice-filter-choice]:checked') !== null;
            rows[i].hidden = !ticked && needle !== '' && text.indexOf(needle) === -1;
        }
    });

    document.addEventListener('DOMContentLoaded', refreshFilterSummary);

    /**
     * Closing the picker, which the browser will not do on its own.
     *
     * A `<details>` is a disclosure in a document: it stays open until its own summary is pressed
     * again, and "outside" means nothing to it. That is right for a paragraph of detail and wrong for
     * a panel floating over a table — every other menu on this page closes when the reader moves on,
     * and this one sat there until it was clicked a second time.
     *
     * So the close is added here and nowhere else. The open, the toggle and the keyboard behaviour of
     * the summary stay the browser's, which is the reason the control is a `<details>` at all.
     *
     * **Clicks inside it must not close it.** Ticking three topics is the ordinary use of a
     * multi-select, and a panel that shut on the first tick could not be used for what it is for. One
     * `contains()` check is the whole rule: the summary, the checkboxes, their labels and the search
     * box are all inside, and everything else is out.
     */
    function openFilterPicker() {
        return document.querySelector('[data-practice-filter-picker][open]');
    }

    document.addEventListener('click', function (event) {
        var picker = openFilterPicker();

        if (picker === null || picker.contains(event.target)) {
            return;
        }

        picker.removeAttribute('open');
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' && event.key !== 'Esc') {
            return;
        }

        var picker = openFilterPicker();

        if (picker === null) {
            return;
        }

        picker.removeAttribute('open');

        // Focus goes back to what opened it, or it would be left on an element that is now hidden and
        // the next Tab would start from the top of the document.
        var summary = picker.querySelector('summary');

        if (summary !== null) {
            summary.focus();
        }
    });

    // Tabbing out of it is leaving it too. `focusin` rather than `blur`, because focus moving between
    // two checkboxes inside the panel must not count as leaving.
    document.addEventListener('focusin', function (event) {
        var picker = openFilterPicker();

        if (picker === null || picker.contains(event.target)) {
            return;
        }

        picker.removeAttribute('open');
    });

    // ---- Practice Orders: the read-only topic details ---------------------------------------------
    //
    // Everything shown here is already on the row that was clicked — the server put it in the trigger's
    // own attributes when it rendered the table. So this opens a dialog and copies four strings into
    // it: no request, no endpoint, and nothing it can show that the reader could not already see.

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-practice-topic-details]');

        if (trigger === null) {
            return;
        }

        var dialog = dialogById('practice-topic-details-dialog');

        if (dialog === null) {
            return;
        }

        var fields = {
            name: trigger.getAttribute('data-practice-topic-name') || '',
            description: trigger.getAttribute('data-practice-topic-description') || '',
            status: trigger.getAttribute('data-practice-topic-status') || '',
            note: trigger.getAttribute('data-practice-topic-note') || '',
            // Only where the page already holds it. `null` means "this screen did not count", which is
            // a different thing from "none", so the row is hidden rather than showing a zero.
            orders: trigger.getAttribute('data-practice-topic-orders')
        };

        var name = dialog.querySelector('[data-practice-details-name]');
        var description = dialog.querySelector('[data-practice-details-description]');
        var status = dialog.querySelector('[data-practice-details-status]');
        var note = dialog.querySelector('[data-practice-details-note]');
        var orders = dialog.querySelector('[data-practice-details-orders]');
        var ordersLabel = dialog.querySelector('[data-practice-details-orders-label]');

        if (name !== null) {
            name.textContent = fields.name;
        }

        if (description !== null) {
            // textContent, not innerHTML: this is a value an operator typed.
            description.textContent = fields.description === ''
                ? 'No description provided'
                : fields.description;
            description.classList.toggle('util-muted', fields.description === '');
        }

        if (status !== null) {
            status.textContent = fields.status;
            // The badge says the same thing the colour does, so the two cannot disagree.
            status.className = 'badge badge--' + (fields.status === 'Active' ? 'success' : 'error');
        }

        if (note !== null) {
            note.textContent = fields.note;
            note.hidden = fields.note === '';
        }

        if (orders !== null && ordersLabel !== null) {
            var counted = fields.orders !== null && fields.orders !== '';
            orders.textContent = counted ? fields.orders : '';
            orders.hidden = !counted;
            ordersLabel.hidden = !counted;
        }

        event.preventDefault();
        open(dialog);
    });

    // Whatever dismissed it — the close button, the backdrop, or Escape — a confirmation that was not
    // confirmed leaves the select showing what is actually stored. `close` fires for all three, which
    // is why this listens for it rather than trying to catch each dismissal separately.
    document.addEventListener('DOMContentLoaded', function () {
        var confirmDialog = dialogById('practice-confirm-dialog');

        if (confirmDialog !== null) {
            confirmDialog.addEventListener('close', restorePending);
        }
    });

    document.addEventListener('DOMContentLoaded', refreshSelection);

    // ---- Order Testing: verifying an order --------------------------------------------------------
    //
    // The control in the table is an ordinary form that posts to an ordinary endpoint, so a browser
    // with no script verifies an order by pressing it. What this adds is the confirmation in between,
    // and the ability to answer without reloading the page — which is also what lets the conversation
    // dialog carry the same control without owning a second copy of the state.
    //
    // There is exactly one piece of state per order on this page: the row's form. The dialog's button
    // reads it and writes through it, so the two cannot disagree and nothing has to be kept in step.

    var verifyRow = null;      // the row form the open conversation dialog is about, or null
    var verifyPending = null;  // the row form waiting on a confirmation, or null

    /** The page's own notice line, which is where an action that did not reload says what happened. */
    function verifyAnnounce(message, kind) {
        var notice = document.querySelector('[data-a2t-notice]');

        if (notice === null) {
            return;
        }

        notice.className = 'a2t-notice alert alert--' + kind;
        notice.textContent = message;
        notice.hidden = false;
    }

    function verifyIsOn(form) {
        return form.getAttribute('data-ot-verified') === '1';
    }

    /**
     * Put one row's control into the state the server just reported.
     *
     * The whole cell is rewritten rather than toggling a class: the two states are different elements —
     * a button and a badge — because they mean different things to a screen reader as well as to an
     * eye, and a badge pretending to be a button was the alternative.
     */
    function verifyPaintRow(form, state) {
        var button = form.querySelector('[data-ot-verify-button]');
        var version = form.querySelector('[data-ot-version]');
        var decision = form.querySelector('[data-ot-decision]');

        form.setAttribute('data-ot-verified', state.verified ? '1' : '0');

        if (version !== null) {
            version.value = String(state.version);
        }

        if (decision !== null) {
            decision.value = state.verified ? 'unverified' : 'verified';
        }

        if (button !== null) {
            if (state.verified) {
                button.className = 'badge badge--success ot-verify__badge';
                button.innerHTML = '<span aria-hidden="true">&check;</span> Verified';
            } else {
                button.className = 'btn btn--sm ot-verify__button';
                button.textContent = 'Verify';
            }
        }

        verifyPaintDialogButton();
    }

    /** The dialog's copy of the control, which exists only while a dialog is open on an order. */
    function verifyPaintDialogButton() {
        var button = document.querySelector('[data-ot-verify-dialog]');

        if (button === null) {
            return;
        }

        if (verifyRow === null) {
            button.hidden = true;
            button.textContent = '';

            return;
        }

        var verified = verifyIsOn(verifyRow);

        button.hidden = false;
        // The same two classes the table's control carries, so the dialog's copy is the same control
        // rather than one that merely does the same thing. It was a plain `btn btn--sm`, which is the
        // neutral button — the one an action must not look like.
        button.className = verified
            ? 'badge badge--success ot-verify__badge'
            : 'btn btn--sm ot-verify__button';
        button.innerHTML = verified ? '<span aria-hidden="true">&check;</span> Verified' : 'Verify';
    }

    function verifyOpenConfirm(form) {
        var dialog = dialogById('ot-verify-dialog');

        if (dialog === null) {
            // No dialog to confirm in: let the form post the ordinary way rather than losing the click.
            form.submit();

            return;
        }

        var verified = verifyIsOn(form);
        var title = dialog.querySelector('[data-ot-verify-title]');
        var order = dialog.querySelector('[data-ot-verify-order]');
        var message = dialog.querySelector('[data-ot-verify-message]');
        var confirm = dialog.querySelector('[data-ot-verify-confirm]');

        if (title !== null) {
            title.textContent = verified ? 'Unverify Order' : 'Verify Order';
        }

        if (order !== null) {
            order.textContent = '#' + (form.getAttribute('data-ot-order') || '');
        }

        if (message !== null) {
            message.textContent = verified
                ? 'This order is currently verified. Are you sure you want to remove its verification '
                    + 'status?'
                : 'Have you fully reviewed and checked this order? Confirming will mark this order as '
                    + 'verified.';
        }

        if (confirm !== null) {
            confirm.textContent = verified ? 'Confirm Unverify' : 'Confirm Verification';
            confirm.className = verified ? 'btn btn--danger btn--sm' : 'btn btn--primary btn--sm';
        }

        verifyPending = form;

        if (!open(dialog)) {
            // No <dialog> support: the confirmation cannot be shown, so the ordinary form post is
            // the honest fallback rather than a click that silently does nothing.
            verifyPending = null;
            form.submit();
        }
    }

    /**
     * Send the decision, and let the server say what the state now is.
     *
     * The body is the form's own fields — including its CSRF token, so this post is protected by the
     * same middleware the no-script one is. `X-Requested-With` is what asks for JSON; the endpoint
     * decides the shape of its answer from headers alone and reads nothing about it from the body.
     */
    function verifySend(form) {
        var button = form.querySelector('[data-ot-verify-button]');

        if (button !== null) {
            button.disabled = true;
        }

        // The attribute, not the property: a form's named controls shadow its own properties, and
        // this form carries several. The attribute is always the URL that was rendered.
        fetch(form.getAttribute('action'), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(form),
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, status: response.status, data: data };
            });
        }).then(function (result) {
            // A conflict still carries the current state: somebody else moved this order on, and the
            // useful answer is what it says now rather than a refusal on its own.
            if (typeof result.data.verified === 'boolean') {
                verifyPaintRow(form, result.data);
            }

            if (result.ok) {
                verifyAnnounce(result.data.message || 'Saved.', 'success');
            } else {
                verifyAnnounce(result.data.error || 'That could not be saved.', 'error');
            }
        }).catch(function () {
            verifyAnnounce('That could not be saved. Check your connection and try again.', 'error');
        }).then(function () {
            if (button !== null) {
                button.disabled = false;
            }
        });
    }

    // The row control, and the dialog's copy of it. Both end in the same confirmation.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-ot-verify]');

        if (form === null) {
            return;
        }

        event.preventDefault();
        verifyOpenConfirm(form);
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-ot-verify-dialog]') !== null && verifyRow !== null) {
            verifyOpenConfirm(verifyRow);

            return;
        }

        if (event.target.closest('[data-ot-verify-confirm]') !== null) {
            var form = verifyPending;

            // Cleared first, so a second click finds nothing pending and cannot send twice.
            verifyPending = null;

            var dialog = dialogById('ot-verify-dialog');

            if (dialog !== null && dialog.open) {
                dialog.close();
            }

            if (form !== null) {
                verifySend(form);
            }

            return;
        }

        // Which order the conversation dialog is about. Only the whole-call opener carries one: a
        // single channel's Details dialog is about a recording, and an order-level decision does not
        // belong on it.
        var opener = event.target.closest('[data-a2t-order-open]');

        if (opener !== null) {
            var row = opener.closest('tr');

            verifyRow = row === null ? null : row.querySelector('[data-ot-verify]');
            verifyPaintDialogButton();

            return;
        }

        if (event.target.closest('[data-a2t-details]') !== null) {
            verifyRow = null;
            verifyPaintDialogButton();
        }
    });

    // The dialog closing ends the order it was about, whichever way it was closed.
    document.addEventListener('DOMContentLoaded', function () {
        var review = document.getElementById('a2t-review-dialog');

        if (review === null) {
            return;
        }

        review.addEventListener('close', function () {
            verifyRow = null;
            verifyPaintDialogButton();
        });
    });

    /*
     * The Store picker: a searchable listbox over the real <select>.
     *
     * ## Why this and not Select2
     *
     * Select2 was the shape asked for, and three things here make it the wrong fit rather than
     * merely a heavy one:
     *
     *   - it is a jQuery plugin, and this application ships no jQuery — vendoring both, unminified
     *     and unversioned, into `assets/` is ~250 KB of third-party code with no build step and no
     *     upgrade path;
     *   - it positions its dropdown by writing `style="top: …; left: …; width: …"` onto elements it
     *     creates, and the policy here is `style-src 'self'` with no `unsafe-inline`, so the browser
     *     refuses those declarations and the list lands in the wrong place;
     *   - it appends that dropdown to `document.body`. These modals are native `<dialog>` elements,
     *     which the browser paints in the top layer, so a body-level dropdown renders *behind* the
     *     modal. `dropdownParent` works around it, and then the panel is clipped by the dialog's own
     *     overflow instead.
     *
     * What is actually wanted is a reliable searchable store selection, so that is what this is: the
     * same `<details>`-and-search idiom the topic filter above already uses, built on the control
     * that was already there.
     *
     * ## The select stays
     *
     * It is hidden, never removed and never disabled — a hidden form control still submits — so the
     * posted field, the server-side validation and the no-script rendering are untouched. Choosing
     * writes through to it and fires `change`, which is what makes the selection real; everything
     * drawn here is a view of that one value.
     */
    var STORE_READY = 'data-practice-store-ready';

    function storeLabel(select) {
        var option = select.options[select.selectedIndex];

        return option === undefined ? '' : option.textContent.trim();
    }

    function storePanelOf(host) {
        return host.querySelector('[data-practice-store-panel]');
    }

    function storeClose(host) {
        var panel = storePanelOf(host);

        if (panel === null || panel.hidden) {
            return;
        }

        panel.hidden = true;
        host.querySelector('[data-practice-store-summary]').setAttribute('aria-expanded', 'false');
    }

    function storeCloseAll() {
        var hosts = document.querySelectorAll('[data-practice-store]');

        for (var i = 0; i < hosts.length; i++) {
            storeClose(hosts[i]);
        }
    }

    /** The options a reader can currently see, in the order they are drawn. */
    function storeVisible(host) {
        var all = host.querySelectorAll('[data-practice-store-option]');
        var out = [];

        for (var i = 0; i < all.length; i++) {
            if (!all[i].hidden) {
                out.push(all[i]);
            }
        }

        return out;
    }

    function storeActivate(host, option) {
        var all = host.querySelectorAll('[data-practice-store-option]');

        for (var i = 0; i < all.length; i++) {
            all[i].classList.remove('is-active');
            all[i].setAttribute('aria-selected', 'false');
        }

        if (option === undefined || option === null) {
            return;
        }

        option.classList.add('is-active');
        option.setAttribute('aria-selected', 'true');
        host.querySelector('[data-practice-store-list]').setAttribute(
            'aria-activedescendant',
            option.id,
        );

        // Only within the list's own scroll box; `block: 'nearest'` is what keeps the dialog itself
        // from being scrolled out from under the reader.
        if (typeof option.scrollIntoView === 'function') {
            option.scrollIntoView({block: 'nearest'});
        }
    }

    function storeChoose(host, option) {
        var select = host.querySelector('[data-practice-store-select]');

        select.value = option.getAttribute('data-value');
        // Dispatched, not assumed: anything else listening for a changed field — now or later — hears
        // this exactly as it would hear a reader using the select directly.
        select.dispatchEvent(new Event('change', {bubbles: true}));

        var summary = host.querySelector('[data-practice-store-summary]');

        summary.querySelector('[data-practice-store-current]').textContent = storeLabel(select);
        storeActivate(host, option);
        storeClose(host);
        summary.focus();
    }

    function storeFilter(host, needle) {
        var options = host.querySelectorAll('[data-practice-store-option]');
        var wanted = needle.trim().toLowerCase();
        var shown = 0;

        for (var i = 0; i < options.length; i++) {
            var hit = wanted === ''
                || options[i].getAttribute('data-search').indexOf(wanted) !== -1;

            options[i].hidden = !hit;

            if (hit) {
                shown++;
            }
        }

        host.querySelector('[data-practice-store-empty]').hidden = shown !== 0;
    }

    function storeOpen(host) {
        var panel = storePanelOf(host);

        if (panel === null || !panel.hidden) {
            return;
        }

        // Every open starts from the whole list rather than from whatever was typed last time, so a
        // reader who closed the panel mid-search does not reopen it on three stores out of 241.
        var search = host.querySelector('[data-practice-store-search]');

        search.value = '';
        storeFilter(host, '');
        panel.hidden = false;
        host.querySelector('[data-practice-store-summary]').setAttribute('aria-expanded', 'true');

        var select = host.querySelector('[data-practice-store-select]');
        var current = host.querySelector(
            '[data-practice-store-option][data-value="' + CSS.escape(select.value) + '"]',
        );

        storeActivate(host, current);
        search.focus();
    }

    /**
     * Build the listbox for one host.
     *
     * The options are read from the select that is already in the page, so there is exactly one list
     * of stores and it is the server's. Nothing is fetched and nothing is duplicated into script.
     */
    function storeBuild(host) {
        // The guard that makes a second call free. The dialog is rendered once per page, but this
        // runs on DOMContentLoaded *and* whenever a dialog opens, and binding twice would give every
        // click two handlers and every keystroke two filters.
        if (host.getAttribute(STORE_READY) === 'true') {
            return;
        }

        var select = host.querySelector('[data-practice-store-select]');

        if (select === null) {
            return;
        }

        host.setAttribute(STORE_READY, 'true');

        var id = select.id || 'practice-store';
        var summary = document.createElement('button');

        // `type="button"`, emphatically: the default inside a <form> is submit, and a picker that
        // posts the form on its first click would create the topic before the store was chosen.
        summary.type = 'button';
        summary.className = 'field__control practice-store__summary';
        summary.id = id + '-summary';
        summary.setAttribute('data-practice-store-summary', '');
        summary.setAttribute('aria-haspopup', 'listbox');
        summary.setAttribute('aria-expanded', 'false');
        summary.setAttribute('aria-controls', id + '-list');
        // The <label> points at the select by id; this reads that same label out loud for the
        // control that has actually taken its place.
        summary.setAttribute('aria-labelledby', id + '-label ' + id + '-summary');

        var current = document.createElement('span');

        current.setAttribute('data-practice-store-current', '');
        current.textContent = storeLabel(select);
        summary.appendChild(current);

        var panel = document.createElement('div');

        panel.className = 'practice-store__panel';
        panel.hidden = true;
        panel.setAttribute('data-practice-store-panel', '');

        var search = document.createElement('input');

        search.type = 'search';
        search.className = 'field__control practice-store__search';
        search.placeholder = 'Search stores';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search stores');
        search.setAttribute('data-practice-store-search', '');
        panel.appendChild(search);

        var list = document.createElement('ul');

        list.className = 'practice-store__options';
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('data-practice-store-list', '');

        for (var i = 0; i < select.options.length; i++) {
            var source = select.options[i];
            var option = document.createElement('li');

            option.className = 'practice-store__option';
            option.id = id + '-option-' + i;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.setAttribute('data-practice-store-option', '');
            option.setAttribute('data-value', source.value);
            option.textContent = source.textContent.trim();
            // Matched against the whole label, so the store name, the company and the id all find
            // the row — which is what makes searching 241 of them bearable.
            option.setAttribute('data-search', option.textContent.toLowerCase());
            list.appendChild(option);
        }

        panel.appendChild(list);

        var empty = document.createElement('p');

        empty.className = 'field__hint practice-store__empty';
        empty.hidden = true;
        empty.setAttribute('data-practice-store-empty', '');
        empty.textContent = 'No store matches that.';
        panel.appendChild(empty);

        host.appendChild(summary);
        host.appendChild(panel);

        // Hidden, not removed and not disabled: it is still the field that posts, and it is what the
        // reader gets back if script ever fails to run.
        select.hidden = true;
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');
    }

    function storeHosts() {
        var hosts = document.querySelectorAll('[data-practice-store]');

        for (var i = 0; i < hosts.length; i++) {
            storeBuild(hosts[i]);
        }
    }

    document.addEventListener('DOMContentLoaded', storeHosts);

    document.addEventListener('click', function (event) {
        var summary = event.target.closest('[data-practice-store-summary]');

        if (summary !== null) {
            var host = summary.closest('[data-practice-store]');

            if (storePanelOf(host).hidden) {
                storeOpen(host);
            } else {
                storeClose(host);
            }

            return;
        }

        var option = event.target.closest('[data-practice-store-option]');

        if (option !== null) {
            storeChoose(option.closest('[data-practice-store]'), option);

            return;
        }

        // Anywhere else — including the dialog's own backdrop — shuts an open panel.
        if (event.target.closest('[data-practice-store-panel]') === null) {
            storeCloseAll();
        }
    });

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-practice-store-search]')) {
            return;
        }

        var host = event.target.closest('[data-practice-store]');

        storeFilter(host, event.target.value);
        // The first match becomes the keyboard target, so typing and pressing Enter picks the store
        // a reader is obviously aiming at without a single arrow key.
        storeActivate(host, storeVisible(host)[0]);
    });

    document.addEventListener('keydown', function (event) {
        var host = event.target.closest('[data-practice-store]');

        if (host === null) {
            return;
        }

        var panel = storePanelOf(host);
        var open = panel !== null && !panel.hidden;

        if (!open) {
            // Closed: the three keys that mean "show me the list".
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                if (event.target.matches('[data-practice-store-summary]')) {
                    event.preventDefault();
                    storeOpen(host);
                }
            }

            return;
        }

        if (event.key === 'Escape') {
            // Stopped here, deliberately. This picker lives inside a <dialog>, and an Escape that
            // reaches the dialog closes it — so without this, dismissing the store list would throw
            // away everything typed into the form behind it.
            event.preventDefault();
            event.stopPropagation();
            storeClose(host);
            host.querySelector('[data-practice-store-summary]').focus();

            return;
        }

        var visible = storeVisible(host);

        if (visible.length === 0) {
            return;
        }

        var active = host.querySelector('[data-practice-store-option].is-active');
        var at = visible.indexOf(active);

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            storeActivate(host, visible[at + 1 >= visible.length ? 0 : at + 1]);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            storeActivate(host, visible[at <= 0 ? visible.length - 1 : at - 1]);
        } else if (event.key === 'Home') {
            event.preventDefault();
            storeActivate(host, visible[0]);
        } else if (event.key === 'End') {
            event.preventDefault();
            storeActivate(host, visible[visible.length - 1]);
        } else if (event.key === 'Enter' && active !== null) {
            // Enter inside the search box must not submit the form on its way past.
            event.preventDefault();
            storeChoose(host, active);
        } else if (event.key === 'Tab') {
            storeClose(host);
        }
    });
})();

