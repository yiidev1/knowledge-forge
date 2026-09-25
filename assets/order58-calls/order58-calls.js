/*
 * Manage Order58 Calls — the select-all checkbox, and nothing else.
 *
 * The page works without this file. Every row carries a real checkbox and Sync is a real submit, so the
 * only thing lost is the convenience of selecting a day's calls in one click. That is deliberate: the
 * administrator's work must not depend on a script arriving.
 *
 * No inline handlers and no inline script — the policy is `script-src 'self'` — so everything here is
 * attached by `addEventListener` against `data-` attributes the template renders.
 */
(function () {
    'use strict';

    var all = document.querySelector('[data-o58-select-all]');
    var list = document.querySelector('[data-o58-calls]');

    if (!all || !list) {
        return;
    }

    /** Only the rows currently drawn: the selection can never reach another store or another day. */
    function boxes() {
        return Array.prototype.slice.call(list.querySelectorAll('input[name="calls[]"]'));
    }

    function selected() {
        return boxes().filter(function (box) { return box.checked; });
    }

    /**
     * Keep the header box honest about the rows beneath it.
     *
     * Three states rather than two: with some rows ticked it is indeterminate, which says "clicking me
     * will select the rest" instead of lying about the current state.
     */
    function sync() {
        var items = boxes();
        var chosen = selected().length;

        all.checked = items.length > 0 && chosen === items.length;
        all.indeterminate = chosen > 0 && chosen < items.length;

        var counter = document.querySelector('[data-o58-count]');

        if (counter) {
            counter.textContent = chosen === 0
                ? 'No calls selected'
                : chosen + (chosen === 1 ? ' call selected' : ' calls selected');
        }
    }

    all.addEventListener('change', function () {
        var checked = all.checked;

        boxes().forEach(function (box) { box.checked = checked; });
        sync();
    });

    // Delegated, so it keeps working if the table is ever re-rendered.
    list.addEventListener('change', function (event) {
        if (event.target && event.target.name === 'calls[]') {
            sync();
        }
    });

    sync();
}());
