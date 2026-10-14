/*
 * The receipt comparison's two pieces of behaviour: printing, and filtering by verdict.
 *
 * Both are bound here rather than written into the markup, because this application sends
 * `script-src 'self'` and an inline handler would be refused by the browser — the button would simply
 * do nothing, silently.
 *
 * Filtering only dims rows. It reads nothing and writes nothing: no request is made and no order is
 * touched, which is what makes it safe to offer on a page about records somebody is auditing.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var print = event.target.closest('[data-rc-print]');

        if (print !== null) {
            event.preventDefault();
            window.print();

            return;
        }

        var chip = event.target.closest('[data-rc-filter]');

        if (chip === null) {
            return;
        }

        var pair = document.querySelector('[data-rc-pair]');

        if (pair === null) {
            return;
        }

        var wanted = chip.getAttribute('data-rc-filter');
        var current = pair.getAttribute('data-rc-filter-active');

        // Clicking the active chip clears the filter, so the page can always be put back without
        // hunting for a "show all" control.
        if (current === wanted) {
            pair.removeAttribute('data-rc-filter-active');
        } else {
            pair.setAttribute('data-rc-filter-active', wanted);
        }

        var active = pair.getAttribute('data-rc-filter-active');
        var chips = document.querySelectorAll('[data-rc-filter]');

        for (var i = 0; i < chips.length; i++) {
            chips[i].setAttribute(
                'aria-pressed',
                chips[i].getAttribute('data-rc-filter') === active ? 'true' : 'false'
            );
        }
    });
})();
