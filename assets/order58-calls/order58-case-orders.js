/*
 * Order58 Case Order synchronisation — the confirmation dialog and the live run panel.
 *
 * The page works without this file. The trigger is a real link to `?confirm-case-orders=1`, which the
 * server answers with the dialog already open; the dialog holds a real form; and the panel is rendered
 * from today's run row, so a refresh shows the truth. What this adds is not having to refresh — which
 * matters here because the request does a provider call plus up to a few hundred imports, and the
 * alternative is watching a pending tab and guessing.
 *
 * No inline handlers and no inline script — the policy is `script-src 'self'` — so everything is
 * attached by `addEventListener` against the `data-o58-case-order-*` attributes the template renders.
 *
 * ## Why the panel polls at all when the POST will answer
 *
 * The POST does the work, so it returns only once everything is finished. The run row is written at
 * each stage, so polling it is the only way to say where the work is while that request is in flight.
 * It is also how an operator whose browser gave up still finds out what happened.
 *
 * ## It stops when there is nothing to watch
 *
 * The server says whether a run is still going, and polling ends the moment it says no. A page whose
 * run has finished — the normal case — makes no requests at all. On an error the interval backs off
 * rather than hammering a server that is already unhappy.
 */
(function () {
    'use strict';

    var panel = document.querySelector('[data-o58-case-order-panel]');

    bindDialog();

    if (!panel || !window.fetch) {
        return;
    }

    var endpoint = panel.getAttribute('data-o58-case-order-status');

    /*
     * The run this panel is about, when the server named one.
     *
     * Polling used to ask "what is the latest run?", which on a quiet page is the last finished one —
     * so the confirmation dialog reported a previous synchronisation as the current operation. Asking
     * about a specific run removes the guess. Before the first confirmation there is no id, and the
     * endpoint then answers only about a run actually in flight, which is the honest answer.
     */
    var runId = panel.getAttribute('data-o58-case-order-run');

    function statusUrl() {
        if (!runId) {
            return endpoint;
        }

        return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'run=' + encodeURIComponent(runId);
    }

    // Two seconds, not the five the recordings table uses. That one watches a scheduled fetch whose
    // state changes at most once a timer tick; this one watches a request that is running right now and
    // moves through four stages in a few seconds, so a five-second poll would miss most of them.
    var INTERVAL = 2000;
    var BACKOFF = 10000;

    // Ten minutes of two-second polls. A run still unfinished after that is not going to be reported by
    // this tab, and a page left open overnight must not keep asking.
    var MAX_POLLS = 300;

    var polls = 0;
    var timer = null;

    if (panel.getAttribute('data-o58-case-order-active') === '1') {
        schedule(INTERVAL);
    }

    // The form is submitted normally — this is a POST that redirects — but the panel starts watching
    // immediately, so the operator sees the stages even though the page is about to navigate. On a slow
    // provider the navigation is what they are waiting for, and this is what tells them it is working.
    var start = document.querySelector('[data-o58-case-order-start]');

    if (start) {
        start.addEventListener('click', function (event) {
            if (start.disabled) {
                event.preventDefault();

                return;
            }

            var form = start.form;

            if (!form) {
                return;
            }

            /*
             * The press is sent from here, and the reply is the answer.
             *
             * It used to submit the form and let the panel poll for a run to appear. The request does
             * the synchronisation itself, so it can take as long as the provider does — and a poll
             * that landed before the claim row was written answered "no run", which the panel could
             * only read as "nothing is happening". That is the `Starting…` that never changed.
             *
             * Posting from script instead means the result arrives on the same request that did the
             * work: one reply, carrying the run it started and the counts it wrote. Nothing has to be
             * discovered, so nothing can be guessed wrong.
             */
            event.preventDefault();

            // One submission. A second press while the first is in flight would post twice.
            start.disabled = true;
            start.textContent = 'Synchronising…';

            runId = null;
            panel.removeAttribute('data-o58-case-order-run');
            clearCounts();
            setText('[data-o58-case-order-state]', 'Synchronising…');

            panel.hidden = false;
            // Indeterminate: the request is one call out and one import back, and this page cannot
            // see inside either. A moving percentage here would be invented.
            indeterminate(true);

            send(form);
        });
    }

    /** A bar with no value is the browser's own "working, duration unknown". */
    function indeterminate(on) {
        var bar = panel.querySelector('[data-o58-case-order-bar]');

        if (!bar) {
            return;
        }

        if (on) {
            bar.removeAttribute('value');
        } else {
            bar.value = 0;
        }
    }

    /**
     * Post the confirmation and render whatever comes back.
     *
     * Every ending is handled, because the one thing this must never do is leave the dialog saying
     * `Starting…` for ever: a refusal, a transport failure, a response that is not JSON, and a
     * request that outlives the timeout all end with a message and a button that can be pressed again.
     */
    function send(form) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;

        /*
         * Longer than the server is allowed to take, not shorter.
         *
         * The synchronisation runs inside the request, and a provider call plus a couple of hundred
         * imports has measured at a few seconds. Five minutes is well past any honest run, so a
         * timeout here means something is genuinely wrong rather than merely slow — and the run row
         * records what happened either way, so giving up on the reply cancels nothing.
         */
        var timer = window.setTimeout(function () {
            if (controller) {
                controller.abort();
            }
        }, 300000);

        /*
         * Sent url-encoded, exactly as the form itself would send it.
         *
         * `FormData` would make it multipart, which is a different parse on the server for a body
         * that carries nothing but a CSRF token. Matching the form keeps one body shape for the
         * scripted and unscripted paths, so the endpoint cannot work for one and not the other.
         */
        window.fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new URLSearchParams(new FormData(form)).toString(),
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'fetch',
                Accept: 'application/json'
            },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (data) {
            window.clearTimeout(timer);
            indeterminate(false);
            finish(data);
        })['catch'](function (error) {
            window.clearTimeout(timer);
            indeterminate(false);
            failed(
                error && error.name === 'AbortError'
                    ? 'The synchronisation is taking longer than expected. It is recorded either '
                        + 'way — reload this page to see how it ended.'
                    : 'The synchronisation could not be submitted. Nothing was started.'
            );
        });
    }

    /** The reply, rendered. A run means counts; no run means the reason there is none. */
    function finish(data) {
        if (!data || typeof data !== 'object') {
            failed('The server sent a reply this page could not read.');

            return;
        }

        if (data.run) {
            runId = String(data.run.id);
            panel.setAttribute('data-o58-case-order-run', runId);
            render(data.run);

            // Still going — only possible when this press joined somebody else's run — so watch it.
            if (data.run.running === true) {
                panel.setAttribute('data-o58-case-order-active', '1');
                schedule(INTERVAL);
            } else {
                panel.setAttribute('data-o58-case-order-active', '0');
            }
        } else {
            setText('[data-o58-case-order-state]', data.success === true ? 'Nothing to do' : 'Refused');
        }

        note(data.message || '');
        start.disabled = false;
        start.textContent = 'Synchronise again';
    }

    function failed(message) {
        setText('[data-o58-case-order-state]', 'Failed');
        note(message);
        panel.setAttribute('data-o58-case-order-active', '0');
        start.disabled = false;
        start.textContent = 'Try again';
    }

    /** The sanitized sentence from the server, or nothing at all. */
    function note(message) {
        var el = panel.querySelector('[data-o58-case-order-message]');

        if (!el) {
            return;
        }

        el.textContent = message;
        el.hidden = message === '';
    }

    /** Blank the previous run's figures, so nothing from it is read as belonging to the new one. */
    function clearCounts() {
        setText('[data-o58-case-order-state]', 'Starting…');
        setText('[data-o58-case-order-received]', 'Received: 0');
        setText('[data-o58-case-order-inserted]', 'New: 0');
        setText('[data-o58-case-order-updated]', 'Updated: 0');
        setText('[data-o58-case-order-unchanged]', 'Unchanged: 0');
        setText('[data-o58-case-order-items]', 'Lines: 0');

        var failed = panel.querySelector('[data-o58-case-order-failed]');

        if (failed) {
            failed.textContent = '';
            failed.hidden = true;
        }

        var note = panel.querySelector('[data-o58-case-order-message]');

        if (note) {
            note.textContent = '';
            note.hidden = true;
        }

        var bar = panel.querySelector('[data-o58-case-order-bar]');

        if (bar) {
            bar.value = 0;
        }
    }

    function schedule(delay) {
        if (timer !== null) {
            window.clearTimeout(timer);
        }

        timer = window.setTimeout(poll, delay);
    }

    function poll() {
        polls += 1;

        if (polls > MAX_POLLS) {
            return;
        }

        window.fetch(statusUrl(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || data.success !== true) {
                    schedule(BACKOFF);

                    return;
                }

                var run = data.run || null;

                if (run) {
                    render(run);
                }

                if (data.running === true) {
                    schedule(INTERVAL);

                    return;
                }

                /*
                 * Terminal. Stop polling and leave the final counts on screen.
                 *
                 * No reload: the modal is the surface for this operation, and reloading underneath it
                 * would throw away the result the operator just asked for. They close it when ready.
                 */
                panel.setAttribute('data-o58-case-order-active', '0');

                if (start) {
                    start.disabled = false;
                    start.textContent = 'Synchronise again';
                }
            })
            .catch(function () {
                schedule(BACKOFF);
            });
    }

    /** Only the parts that move. Everything else is already correct from the server's render. */
    function render(run) {
        var bar = panel.querySelector('[data-o58-case-order-bar]');

        if (bar && typeof run.percent === 'number') {
            bar.value = run.percent;
        }

        setText('[data-o58-case-order-state]', run.statusLabel);
        setText('[data-o58-case-order-received]', 'Received: ' + numberOf(run.ordersReceived));
        setText('[data-o58-case-order-inserted]', 'New: ' + numberOf(run.ordersInserted));
        setText('[data-o58-case-order-updated]', 'Updated: ' + numberOf(run.ordersUpdated));
        setText('[data-o58-case-order-unchanged]', 'Unchanged: ' + numberOf(run.ordersUnchanged));
        setText('[data-o58-case-order-items]', 'Lines: ' + numberOf(run.itemsImported));

        var failed = panel.querySelector('[data-o58-case-order-failed]');

        if (failed) {
            failed.hidden = numberOf(run.failures) === 0;
            failed.textContent = 'Failed: ' + numberOf(run.failures);
        }

        var note = panel.querySelector('[data-o58-case-order-message]');

        if (note) {
            // Only what the server sent. Never an invented explanation.
            note.hidden = typeof run.message !== 'string' || run.message === '';
            note.textContent = typeof run.message === 'string' ? run.message : '';
        }

        var state = panel.querySelector('[data-o58-case-order-state]');

        if (state && typeof run.modifier === 'string') {
            // The class comes from the server's own enum, so this never invents a variant the
            // stylesheet does not define.
            state.className = 'badge badge--' + run.modifier;
        }
    }

    function setText(selector, text) {
        var node = panel.querySelector(selector);

        if (node && typeof text === 'string') {
            node.textContent = text;
        }
    }

    function numberOf(value) {
        return typeof value === 'number' ? value : 0;
    }

    /**
     * The confirmation, upgraded from a navigation to a modal.
     *
     * Bound whether or not the panel is present, because the trigger and the dialog are independent of
     * it. `showModal()` gives Escape, the backdrop and the focus trap for free; the link it replaces
     * still works when this file does not load.
     */
    function bindDialog() {
        var dialog = document.querySelector('[data-o58-case-order-dialog]');
        var open = document.querySelector('[data-o58-case-order-open]');

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        if (open) {
            open.addEventListener('click', function (event) {
                event.preventDefault();
                dialog.showModal();
            });
        }

        var cancel = dialog.querySelector('[data-o58-case-order-cancel]');

        if (cancel) {
            cancel.addEventListener('click', function (event) {
                event.preventDefault();
                dialog.close();
            });
        }

        /*
         * Closing stops the watching, not the work.
         *
         * The run is a row and a worker; this dialog only reports on it. Polling is dropped so a
         * closed modal costs nothing, and reopening the page picks the run up again from the server.
         */
        dialog.addEventListener('close', function () {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }
        });
    }
}());
