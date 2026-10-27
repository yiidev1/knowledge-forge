/*
 * Case Orders — View opens the order in a dialog instead of navigating away.
 *
 * The page works without this file. Every View is a real link to a real detail page, so with the
 * script blocked or broken the behaviour is the one the page had before the dialog existed. What this
 * adds is not losing the list: an operator checking a dozen orders against their recordings should not
 * have to go back and re-apply filters and paging between each one.
 *
 * No inline handlers — the policy is `script-src 'self'` — so the link is upgraded by a single
 * delegated listener against the `data-o58-co-*` attributes the template renders.
 *
 * ## It asks for a fragment, not the page
 *
 * `?fragment=1` on the same URL returns the body with no layout, from the same action behind the same
 * admin group. Injecting a whole page into a dialog would nest a second `<main>`, a second set of
 * breadcrumbs and a second "Back to Case Orders" inside the first.
 *
 * ## Anything unexpected falls back to following the link
 *
 * A failed fetch, a non-200, or a browser without `<dialog>` support lets the navigation happen
 * normally. The one thing this must never do is swallow the click and show nothing.
 */
(function () {
    'use strict';

    var dialog = document.querySelector('[data-o58-co-dialog]');

    if (!dialog || !window.fetch || typeof dialog.showModal !== 'function') {
        return;
    }

    var body = dialog.querySelector('[data-o58-co-dialog-body]');
    var title = dialog.querySelector('[data-o58-co-dialog-title]');

    if (!body) {
        return;
    }

    // One listener on the table's container rather than one per row, so paging or filtering — which
    // replaces the rows — cannot leave the new buttons unbound.
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('[data-o58-co-view]') : null;

        if (!link) {
            return;
        }

        // Let the browser do its usual thing for the gestures that mean "somewhere else": a new tab,
        // a new window, a download. Only a plain left click becomes a dialog.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
            || event.shiftKey || event.altKey) {
            return;
        }

        var href = link.getAttribute('href');

        if (!href) {
            return;
        }

        event.preventDefault();
        open(href, link);
    });

    function open(href, link) {
        var row = link.closest ? link.closest('tr') : null;
        var number = row ? row.getAttribute('data-o58-co-order') : null;

        if (title) {
            title.textContent = number ? 'Case order #' + number : 'Case order';
        }

        body.innerHTML = '<p class="util-muted">Loading…</p>';
        dialog.showModal();

        fetch(href + (href.indexOf('?') === -1 ? '?' : '&') + 'fragment=1', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.text();
        }).then(function (html) {
            body.innerHTML = html;
            // Move focus into the panel so the dialog reads as one region and Escape still closes it.
            body.focus();
        })['catch'](function () {
            /*
             * Say what happened and offer the page, rather than leaving an empty dialog.
             *
             * The link is built here from the href already in hand — not from anything the server
             * just sent — so a failed request cannot put a URL of its choosing on the page.
             */
            body.textContent = '';

            var note = document.createElement('p');
            note.className = 'util-muted';
            note.textContent = 'This order could not be loaded here. ';

            var fallback = document.createElement('a');
            fallback.setAttribute('href', href);
            fallback.textContent = 'Open the detail page instead.';

            note.appendChild(fallback);
            body.appendChild(note);
        });
    }

    // Clicking the backdrop closes it, which is what every other dialog on the site does.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    // Drop the order when the dialog closes: a reopened dialog must never flash the previous one.
    dialog.addEventListener('close', function () {
        body.innerHTML = '';
    });
}());
