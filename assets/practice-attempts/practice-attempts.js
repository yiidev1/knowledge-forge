/*
 * Retry Practice: record the attempt, then go to Order58 — one click, no stop in between.
 *
 * ## Why this is a script and not a redirect
 *
 * The obvious implementation is a form that POSTs and gets a 303 to Order58. It does not work here,
 * and the reason is recorded in `DemoUrl\Action`: this application sends `form-action 'self'`, and
 * **Chrome and Safari enforce `form-action` across redirects**. A form submission that ends up at
 * order58.com is blocked even though the POST itself was same-origin — the tab lands on about:blank.
 * Widening the directive is not an option either: the hosts come from a mirrored column, so they
 * cannot be enumerated honestly.
 *
 * A script-initiated navigation is not a form submission, so no directive in the policy restricts it.
 * That is what this does, and it is the same escape the Manage Orders table already uses.
 *
 * ## A second tab, opened inside the gesture
 *
 * Order58 opens in a new tab so this page stays where it is. The tab is opened **synchronously in
 * the submit handler**, while the click's transient user activation is still live, and only pointed
 * at the destination once the POST has answered. Calling `window.open()` after the `await` instead
 * is the version every popup blocker stops: by then the gesture is spent.
 *
 * The form itself carries `target="_blank"` for the case where this script is absent: the browser
 * then posts it natively into a new tab and the endpoint answers there with its own page, leaving
 * the history open. What that path cannot do is redirect on to Order58 — `form-action` is enforced
 * across redirects, as above — so it shows the link instead, and this script is what spares anybody
 * that click.
 *
 * ## The attempt is recorded first, and a failure never navigates
 *
 * The POST is awaited. Only a 2xx carrying a URL moves the browser; anything else leaves the reader
 * here with the server's own message. A refusal must never look like a successful start.
 */
(function () {
    'use strict';

    var FORM = '[data-pa-retry]';

    function message(form, text) {
        var box = form.parentNode.querySelector('[data-pa-retry-error]');

        if (box === null) {
            return;
        }

        box.textContent = text;
        box.hidden = text === '';
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest(FORM);

        if (form === null) {
            return;
        }

        /*
         * Nothing to take over with: no fetch, no FormData.
         *
         * Left to the browser, which posts the form into the new tab its `target` asks for. The
         * attempt is still recorded and the endpoint's page carries the link — one extra click,
         * which is the whole cost of not having this script.
         */
        if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
            return;
        }

        // Taken over entirely: the native submission is the one the policy would block.
        event.preventDefault();

        var button = form.querySelector('button[type="submit"]');

        // A second press while the first is in flight would start a second attempt for the same
        // person. The button is disabled for the whole round trip rather than debounced on a timer.
        if (button !== null && button.disabled) {
            return;
        }

        if (button !== null) {
            button.disabled = true;
        }

        message(form, '');

        /*
         * Opened now, empty, while the gesture is still attributable to the click.
         *
         * It carries `noopener` so the new tab gets no handle on this one. If the browser refuses
         * anyway the result is null, and the fetch below falls back to navigating this tab rather
         * than silently doing nothing.
         */
        var tab = window.open('', '_blank', 'noopener');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            // What tells the action to answer with JSON instead of its own page.
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return {ok: response.ok, data: data};
                });
            })
            .then(function (result) {
                if (result.ok && result.data && typeof result.data.url === 'string') {
                    // The destination is the server's, built from mirrored store data. Nothing the
                    // browser holds contributes to it.
                    if (tab !== null && !tab.closed) {
                        tab.location = result.data.url;
                    } else {
                        /*
                         * A blocker refused the tab outright.
                         *
                         * This tab is NOT navigated instead: the attempt is already recorded, so
                         * sending the reader away would cost them the history they are standing on
                         * and earn nothing. They get the address, which is what the Practice Order
                         * button on the table does in the same situation.
                         */
                        message(form, 'Your browser blocked the new tab. The attempt is recorded; '
                            + 'open ' + result.data.url);
                    }

                    if (button !== null) {
                        button.disabled = false;
                    }

                    return;
                }

                // Nothing was started, so the blank tab has nowhere to go and must not be left open.
                if (tab !== null && !tab.closed) {
                    tab.close();
                }

                if (button !== null) {
                    button.disabled = false;
                }

                message(form, (result.data && result.data.message)
                    || 'The attempt could not be started. Nothing was recorded.');
            })
            .catch(function () {
                if (tab !== null && !tab.closed) {
                    tab.close();
                }

                if (button !== null) {
                    button.disabled = false;
                }

                message(form, 'The attempt could not be started. Nothing was recorded.');
            });
    });
})();
