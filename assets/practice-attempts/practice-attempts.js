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

/*
 * The View Tries drawer.
 *
 * ## Why a drawer and not a page
 *
 * A reviewer working through a hundred practitioners opens one, reads four results, closes it and
 * carries on. A page per practitioner costs them their place in the table, their search, their sort
 * and their scroll position every single time — four navigations to look at one person.
 *
 * So the drawer fetches the practitioner's own page and lifts its contents in, and the table behind
 * it is never touched. Nothing about the table's state can be lost because nothing about the table
 * changes.
 *
 * ## It is an enhancement, never the only way
 *
 * Every row's View Tries is a real link to a real page. This intercepts the click; with no script,
 * or if the fetch fails, the link does what links do. That is also why the endpoint renders a whole
 * page rather than a bare fragment — one response serves both, so the two cannot drift apart.
 *
 * ## Only the practitioner asked for
 *
 * One request, for one person, when somebody asks. The table ships no histories at all, which is
 * what keeps a hundred rows from costing a hundred comparisons — and the server re-checks who may
 * be read, so this script is a convenience and never the authorization.
 */
(function () {
    'use strict';

    var drawer = document.querySelector('[data-pr-drawer]');

    if (drawer === null) {
        return;
    }

    var panel = drawer.querySelector('[data-pr-drawer-panel]');
    var body = drawer.querySelector('[data-pr-drawer-body]');
    var title = drawer.querySelector('[data-pr-drawer-title]');
    // What had focus when the drawer opened, so closing can give it back. Without this the next Tab
    // starts from the top of the document and the reader loses the row they were on.
    var opener = null;

    function open(name) {
        opener = document.activeElement;
        title.textContent = name;
        body.innerHTML = '';
        body.appendChild(loading());
        drawer.hidden = false;
        // Focus the panel, not the first control inside it: the contents are still loading, and
        // moving focus to something that is about to be replaced loses it again a moment later.
        panel.focus();
    }

    function close() {
        drawer.hidden = true;
        body.innerHTML = '';

        if (opener !== null && typeof opener.focus === 'function') {
            opener.focus();
        }

        opener = null;
    }

    function loading() {
        var p = document.createElement('p');
        p.className = 'util-muted';
        p.textContent = 'Loading…';

        return p;
    }

    function failed(url) {
        // A failure says so and leaves the way through: the link still works, and the reader should
        // not be left with an empty panel wondering whether this person has no results.
        var p = document.createElement('p');
        p.className = 'util-muted';
        p.textContent = 'Could not load these tries. ';

        var a = document.createElement('a');
        a.href = url;
        a.textContent = 'Open them as a page';
        p.appendChild(a);

        return p;
    }

    /**
     * Fetch one practitioner's tries and put them in the panel.
     *
     * The response is a whole page, so the part that belongs in a drawer is lifted out of it by the
     * marker the template puts around exactly that. Parsed with DOMParser rather than assigned as
     * innerHTML: the document never becomes live, so nothing in it can run.
     */
    function load(url) {
        window.fetch(url, {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('status ' + response.status);
            }

            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var content = parsed.querySelector('[data-pr-tries-content]');

            body.innerHTML = '';

            if (content === null) {
                body.appendChild(failed(url));

                return;
            }

            body.appendChild(document.importNode(content, true));
        }).catch(function () {
            body.innerHTML = '';
            body.appendChild(failed(url));
        });
    }

    document.addEventListener('click', function (event) {
        var close$ = event.target.closest('[data-pr-drawer-close]');

        if (close$ !== null) {
            event.preventDefault();
            close();

            return;
        }

        // Paging inside the drawer stays inside the drawer.
        var pageLink = event.target.closest('[data-pr-tries-page]');

        if (pageLink !== null && !drawer.hidden) {
            event.preventDefault();
            body.innerHTML = '';
            body.appendChild(loading());
            load(pageLink.getAttribute('href'));

            return;
        }

        var trigger = event.target.closest('[data-pr-tries]');

        if (trigger === null) {
            return;
        }

        // Only a plain click. A middle click, or ctrl/cmd, means "open this in a tab" — and it is a
        // real link, so letting the browser do that is the correct answer.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey
            || event.shiftKey || event.altKey) {
            return;
        }

        if (typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
            return;
        }

        event.preventDefault();
        open(trigger.getAttribute('data-pr-agent') || 'Practitioner');
        load(trigger.getAttribute('data-pr-tries'));
    });

    document.addEventListener('keydown', function (event) {
        if ((event.key === 'Escape' || event.key === 'Esc') && !drawer.hidden) {
            close();
        }
    });
})();
