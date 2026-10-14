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
})();
