/*
 * Order58 Call Recordings — live download progress.
 *
 * The page works without this file: every cell is rendered by the server and a refresh shows the truth.
 * What this adds is not having to press refresh, which matters here because the recordings arrive over
 * a minute or two and the alternative is watching a static page and guessing.
 *
 * No inline handlers and no inline script — the policy is `script-src 'self'` — so everything is
 * attached by `addEventListener` against `data-` attributes the template renders.
 *
 * ## One request for the whole table
 *
 * Every visible call id goes in one query string and comes back in one answer. A request per row would
 * turn a twenty-call day into twenty requests every five seconds from every open tab.
 *
 * ## It stops when there is nothing to watch
 *
 * The server says whether anything is still active, and polling ends the moment it says no. A finished
 * page makes no requests at all, so a tab left open overnight costs nothing. On an error the interval
 * backs off rather than hammering a server that is already unhappy.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-o58-status]');

    if (!root || !window.fetch) {
        return;
    }

    var endpoint = root.getAttribute('data-o58-status');
    var store = root.getAttribute('data-o58-store');

    // Five seconds, not the two the conversion page uses. That one watches a worker already holding the
    // file; this one watches a scheduled fetch whose state changes at most once per timer tick, so a
    // two-second poll would spend most of its requests learning nothing.
    var INTERVAL = 5000;
    var BACKOFF = 20000;
    var MAX_QUIET_POLLS = 180;

    var timer = null;
    var stopped = false;
    var polls = 0;

    function cells() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-o58-call]'));
    }

    function ids() {
        return cells()
            .map(function (cell) { return cell.getAttribute('data-o58-call'); })
            .filter(function (id) { return id; });
    }

    function stop() {
        stopped = true;

        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    function text(cell, selector, value) {
        var node = cell.querySelector(selector);

        if (node && typeof value === 'string') {
            node.textContent = value;
        }
    }

    function badge(cell, selector, label, modifier) {
        var node = cell.querySelector(selector);

        if (!node || typeof label !== 'string') {
            return;
        }

        node.textContent = label;
        node.className = 'badge badge--' + (modifier || 'muted');
    }

    /**
     * Redraw one call from the server's own model.
     *
     * Nothing is computed here — not the percentage, not the counts, not which word to use. The server
     * derives all of it so that the first paint and every poll afterwards cannot disagree, and so the
     * rule about what the bar means lives in one place rather than in two languages.
     */
    function render(cell, call) {
        badge(cell, '[data-o58-outcome]', call.outcomeLabel, call.outcomeBadge);
        text(cell, '[data-o58-availability]', call.availabilityText);

        var panel = cell.querySelector('[data-o58-panel]');

        if (panel && !call.active) {
            // The work finished while this page was watching. The panel's whole subject is the thing
            // that was moving, so it goes rather than freezing at full — a bar that stays at 100% on
            // every finished row is noise on most of the table, most of the time.
            panel.remove();
            panel = null;
        }

        if (panel) {
            text(panel, '[data-o58-progress-text]', call.progressText);
            text(panel, '[data-o58-current]', call.currentStep);

            var bar = panel.querySelector('[data-o58-bar]');

            if (bar && typeof call.percentChecked === 'number') {
                bar.value = call.percentChecked;
                // The words, not the number: a reader told "33 percent" would be hearing the one
                // figure here that is not the authoritative one.
                bar.setAttribute('aria-label', call.progressText || '');
            }
        }

        if (!call.channels) {
            return;
        }

        Object.keys(call.channels).forEach(function (channel) {
            var row = cell.querySelector('[data-o58-channel="' + channel + '"]');

            if (!row) {
                return;
            }

            badge(row, '[data-o58-channel-state]', call.channels[channel].label, call.channels[channel].badge);

            // The mark comes from the server for the same reason the words do: one place decides what
            // a state looks like, and the browser does not get its own opinion.
            if (call.channels[channel].step) {
                row.setAttribute('data-state', call.channels[channel].step);
            }
        });
    }

    function poll() {
        if (stopped) {
            return;
        }

        var wanted = ids();

        if (!wanted.length) {
            stop();
            return;
        }

        var url = endpoint
            + (endpoint.indexOf('?') === -1 ? '?' : '&')
            + 'store=' + encodeURIComponent(store)
            + '&calls=' + encodeURIComponent(wanted.join(','));

        window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('status ' + response.status);
                }

                return response.json();
            })
            .then(function (data) {
                if (stopped || !data || !data.calls) {
                    return;
                }

                cells().forEach(function (cell) {
                    var call = data.calls[cell.getAttribute('data-o58-call')];

                    if (call) {
                        render(cell, call);
                    }
                });

                // The server decides. "Nothing is active" is a fact about rows, and the browser is in no
                // position to work it out from what it can see.
                if (!data.active) {
                    stop();
                    return;
                }

                // A backstop for the case the server keeps saying "active" because something is genuinely
                // stuck — fifteen minutes of asking is enough, and a refresh restarts it.
                if (++polls >= MAX_QUIET_POLLS) {
                    stop();
                    return;
                }

                timer = window.setTimeout(poll, INTERVAL);
            })
            .catch(function () {
                if (stopped) {
                    return;
                }

                // Nothing is said on screen about a lost poll: the rows are still true as drawn, and a
                // warning about this page's own connection would be noise about the wrong thing.
                timer = window.setTimeout(poll, BACKOFF);
            });
    }

    // Only start if something is actually moving. A page of finished downloads polls zero times.
    if (root.getAttribute('data-o58-active') === '1') {
        timer = window.setTimeout(poll, INTERVAL);
    }

    // A tab in the background need not keep asking; it catches up when it comes back.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden || stopped || timer) {
            return;
        }

        timer = window.setTimeout(poll, INTERVAL);
    });
}());
