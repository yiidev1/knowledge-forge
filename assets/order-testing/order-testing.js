/* Order Testing — behaviour of its own.

   The store page's script, loaded by this bundle's dependency, drives uploads, modals, polling,
   review, replacement and generated audio: the template renders the same `data-a2t-*` attributes it
   binds to, carrying Order Testing's own URLs. This file is for behaviour that belongs to THIS
   surface and not that one, so it never becomes an `if (page === 'order-testing')` branch over there.

   Everything here is scoped to `data-ot-*` attributes, so a page without them starts no timer and no
   fetch. */

(function () {
    'use strict';

    /* ---- Demo URL: record the attempt, then open Order58 in a new tab ----------------------------

       Why this exists at all, since a <form target="_blank"> already opens a new tab:

       `form-action 'self'` in the application's CSP is enforced ACROSS REDIRECTS by Chrome and
       Safari. The old cell posted here and received a 303 to `https://…order58.com/…`; the new tab
       opened and then sat on about:blank, because the redirect's destination is not in `form-action`.
       Ctrl-clicking looked like it worked only because a link navigation is not a form submission.

       A navigation this script performs is not a form submission either, and no directive in the
       policy restricts it. So: POST here for the attempt, read back the server-built URL, and point
       a tab at it.

       THE ORDER OF THE FIRST TWO STATEMENTS IS THE WHOLE TRICK. The tab is opened SYNCHRONOUSLY,
       inside the click event, before any network call. A window opened later — in a promise callback,
       after an await — is no longer attributable to the user's gesture and every popup blocker
       refuses it. Opening first and navigating later is what makes a single normal left-click work.

       The form stays a real form. With this script absent the POST still happens, the attempt is
       still recorded, and the server answers with a page carrying the link. */

    var ATTEMPT = '[data-ot-demo-url]';

    function message(button, text) {
        var cell = button.closest('td') || button.parentNode;
        var box = cell ? cell.querySelector('[data-ot-demo-message]') : null;

        if (!box) {
            return;
        }

        // textContent, never innerHTML: the text is a server message and may one day quote an
        // operator's name.
        box.textContent = text;
        box.hidden = text === '';
    }

    function release(button) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }

    document.addEventListener('click', function (event) {
        var target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        var button = target.closest(ATTEMPT);

        if (!button) {
            return;
        }

        var form = button.closest('form');

        // No fetch, no FormData, no form: leave the browser to submit it normally. The server answers
        // that path with a page that has the link on it, so an old browser still gets there.
        if (!form || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
            return;
        }

        event.preventDefault();

        // Double-click protection. One click is one attempt; the second is ignored rather than
        // queued, because the second would be refused by the server anyway and would close a tab the
        // first one had legitimately opened.
        if (button.disabled) {
            return;
        }

        message(button, '');

        // FIRST. See the note above.
        var tab = window.open('about:blank', '_blank');

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        window.fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            // The CSRF token is a field in that FormData, exactly as the normal POST sends it.
            // Both headers, because the server accepts either and the rest of this application's
            // JSON endpoints are reached with X-Requested-With.
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            }, function () {
                return { ok: false, data: {} };
            });
        }).then(function (result) {
            if (result.ok && typeof result.data.url === 'string' && result.data.url !== '') {
                if (tab) {
                    // The URL was built server-side from the mirrored host and phone and checked
                    // against the host allow-list. This script never composes one.
                    tab.location = result.data.url;
                } else {
                    // A blocker refused the tab outright. The attempt is already recorded, so the
                    // operator must not be sent round again: give them the address to open.
                    message(button, 'Your browser blocked the new tab. The test is recorded; open ' + result.data.url);
                }

                release(button);

                return;
            }

            // Refused — most often because somebody else holds this order. Never leave a blank tab
            // sitting there suggesting something is loading.
            if (tab) {
                tab.close();
            }

            message(button, typeof result.data.error === 'string'
                ? result.data.error
                : 'The demo page could not be opened. Please try again.');
            release(button);
        }).catch(function () {
            if (tab) {
                tab.close();
            }

            message(button, 'The demo page could not be opened. Please try again.');
            release(button);
        });
    });
}());
