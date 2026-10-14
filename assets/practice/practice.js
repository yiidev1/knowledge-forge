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

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-practice-set-topic]');

        if (trigger === null) {
            return;
        }

        var dialog = dialogById('practice-assign-dialog');

        if (dialog === null) {
            return;
        }

        var orderId = trigger.getAttribute('data-practice-set-topic');
        var current = trigger.getAttribute('data-practice-current') || '';

        var orderField = dialog.querySelector('[data-practice-assign-order]');
        var label = dialog.querySelector('[data-practice-assign-label]');
        var topic = dialog.querySelector('[data-practice-assign-topic]');

        if (orderField !== null) {
            orderField.value = orderId;
        }

        if (label !== null) {
            label.textContent = '#' + orderId;
        }

        // Pre-select what the row already carries, so "Change" opens on the current answer rather
        // than on the top of the list.
        if (topic !== null) {
            topic.value = current;
        }

        event.preventDefault();
        open(dialog);
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

    document.addEventListener('DOMContentLoaded', refreshSelection);
})();
