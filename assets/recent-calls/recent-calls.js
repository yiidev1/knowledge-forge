/**
 * Order58 Recent Calls: load one store's latest calls, and play or download each recording on demand.
 *
 *  - One request per Load Calls press. A second press, or a change of store, cancels the one still in
 *    flight, and an answer that arrives for a store no longer selected is ignored — so a slow response
 *    can never paint the wrong store's calls.
 *  - No audio is requested until a Play or Download is pressed, and only that one channel of that call.
 *  - One recording plays at a time. Pressing another stops the first; reloading the table releases it.
 *  - Every value is written with textContent, and every address arrives built by the server; nothing
 *    here composes a URL beyond adding the selected store to the list address it was given.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-rc]');
    if (!root) {
        return;
    }

    var form = root.querySelector('[data-rc-form]');
    var select = root.querySelector('[data-rc-store]');
    var button = root.querySelector('[data-rc-load]');
    var status = root.querySelector('[data-rc-status]');
    var body = root.querySelector('[data-rc-body]');
    var channelHeads = root.querySelectorAll('[data-rc-channel]');
    var listUrl = root.getAttribute('data-rc-list') || '';
    var columnCount = 4 + channelHeads.length;

    var channels = Array.prototype.map.call(channelHeads, function (th) {
        return { key: th.getAttribute('data-rc-channel'), label: th.textContent.trim() };
    });

    var inFlight = null;
    var requestNumber = 0;

    /* ---- the player ---------------------------------------------------------------------- */

    var playing = null;

    function mark(control, on) {
        if (on) {
            control.setAttribute('data-playing', '');
        } else {
            control.removeAttribute('data-playing');
            control.removeAttribute('data-loading');
        }
        control.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    function release(audio) {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    }

    function stop() {
        if (playing === null) {
            return;
        }
        release(playing.audio);
        mark(playing.button, false);
        playing = null;
    }

    function broken(control) {
        stop();
        mark(control, false);
        control.classList.add('is-broken');
        control.title = 'This recording could not be played. It may not be available for this call. '
            + 'Press to try again.';
    }

    function toggle(control) {
        if (playing !== null && playing.button === control) {
            stop();
            return;
        }
        stop();

        var audio = new Audio();
        audio.preload = 'none';
        audio.src = control.getAttribute('data-rc-play');
        audio.addEventListener('ended', function () {
            if (playing !== null && playing.audio === audio) {
                stop();
            }
        });
        audio.addEventListener('waiting', function () {
            if (playing !== null && playing.audio === audio) {
                control.setAttribute('data-loading', '');
            }
        });
        audio.addEventListener('playing', function () {
            control.removeAttribute('data-loading');
            control.classList.remove('is-broken');
        });
        audio.addEventListener('error', function () {
            if (playing !== null && playing.audio === audio) {
                broken(control);
            }
        });

        playing = { button: control, audio: audio };
        mark(control, true);
        control.setAttribute('data-loading', '');

        var started = audio.play();
        if (started && typeof started.catch === 'function') {
            started.catch(function (error) {
                // A pause pressed before the first byte arrived is not a broken recording.
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (playing !== null && playing.audio === audio) {
                    broken(control);
                }
            });
        }
    }

    body.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var control = target.closest('[data-rc-play]');
        if (control) {
            event.preventDefault();
            toggle(control);
        }
    });

    /* ---- the table ----------------------------------------------------------------------- */

    /** Only an address on this site, as the server builds them. */
    function localUrl(value) {
        return typeof value === 'string' && value.charAt(0) === '/' && value.charAt(1) !== '/' ? value : null;
    }

    function text(value) {
        return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
    }

    function cell(content, className) {
        var td = document.createElement('td');
        if (className) {
            td.className = className;
        }
        if (typeof content === 'string') {
            td.textContent = content;
        } else if (content) {
            td.appendChild(content);
        }
        return td;
    }

    function dash() {
        var span = document.createElement('span');
        span.className = 'util-muted';
        span.textContent = '—';
        return span;
    }

    function message(textContent, className) {
        stop();
        body.textContent = '';
        var tr = document.createElement('tr');
        tr.className = 'rc-placeholder';
        var td = cell(textContent, className || 'util-muted');
        td.colSpan = columnCount;
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function audioControls(recording, channel, call) {
        var play = recording ? localUrl(recording.play) : null;
        var download = recording ? localUrl(recording.download) : null;

        if (play === null || download === null) {
            var none = dash();
            none.title = 'No ' + channel.label + ' can be requested for this call.';
            return none;
        }

        var what = channel.label + ' of call ' + text(call.sessionId);
        var pair = document.createElement('span');
        pair.className = 'rc-pair';

        var playButton = document.createElement('button');
        playButton.type = 'button';
        playButton.className = 'rc-play';
        playButton.setAttribute('data-rc-play', play);
        playButton.setAttribute('aria-pressed', 'false');
        playButton.title = 'Play ' + what;
        playButton.setAttribute('aria-label', 'Play ' + what);
        var icon = document.createElement('span');
        icon.className = 'rc-play__icon';
        icon.setAttribute('aria-hidden', 'true');
        playButton.appendChild(icon);

        var link = document.createElement('a');
        link.className = 'rc-download';
        link.href = download;
        link.setAttribute('download', text(recording.fileName));
        link.title = 'Download ' + what;
        link.setAttribute('aria-label', 'Download ' + what);
        link.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            + '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>';

        pair.appendChild(playButton);
        pair.appendChild(link);
        return pair;
    }

    function receiptLink(call) {
        var url = localUrl(call.receipt);
        if (url === null) {
            var none = dash();
            none.title = text(call.orderNo) !== ''
                ? 'This call\u2019s order number is not one a receipt can be requested for.'
                : 'This call has no order number.';
            return none;
        }
        var link = document.createElement('a');
        link.className = 'btn btn--secondary btn--sm';
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'View Receipt';
        link.setAttribute('aria-label', 'View the receipt for order ' + text(call.orderNo) + ' (opens in a new tab)');
        return link;
    }

    function render(calls) {
        stop();
        body.textContent = '';

        calls.forEach(function (call) {
            var tr = document.createElement('tr');
            tr.appendChild(cell(text(call.orderNo) !== '' ? '#' + text(call.orderNo) : dash(), 'rc-strong'));
            tr.appendChild(cell(text(call.sessionId) !== '' ? text(call.sessionId) : dash(), 'util-mono'));
            tr.appendChild(cell(text(call.callTime) !== '' ? text(call.callTime) : dash(), 'rc-nowrap'));
            channels.forEach(function (channel) {
                var recording = call.channels && call.channels[channel.key] ? call.channels[channel.key] : null;
                tr.appendChild(cell(audioControls(recording, channel, call)));
            });
            tr.appendChild(cell(receiptLink(call)));
            body.appendChild(tr);
        });
    }

    function storeName() {
        var option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : '';
    }

    function settle() {
        inFlight = null;
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }

    function load() {
        if (inFlight !== null) {
            inFlight.abort();
        }

        var number = ++requestNumber;
        var store = select.value;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        inFlight = controller || { abort: function () {} };

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        status.textContent = 'Loading the latest calls for ' + storeName() + '…';
        message('Loading calls…');

        var url = listUrl + (listUrl.indexOf('?') === -1 ? '?' : '&') + 'store=' + encodeURIComponent(store);

        window.fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                }).then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                // A newer press, or another store, has taken over: this answer is stale.
                if (number !== requestNumber || store !== select.value) {
                    return;
                }
                settle();

                var data = result.data || {};

                if (!result.ok || !Array.isArray(data.calls)) {
                    var problem = text(data.error) || 'The calls could not be loaded. Try again shortly.';
                    status.textContent = '';
                    message(problem + (text(data.detail) ? ' ' + text(data.detail) : ''), 'rc-error');
                    return;
                }

                if (data.calls.length === 0) {
                    status.textContent = '';
                    message('No recent calls were found for ' + storeName() + '.');
                    return;
                }

                status.textContent = 'Showing the latest ' + data.calls.length + ' calls for ' + storeName() + ', newest first.';
                render(data.calls);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (number !== requestNumber) {
                    return;
                }
                settle();
                status.textContent = '';
                message('The calls could not be loaded. Check the connection and try again.', 'rc-error');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        load();
    });

    // Another store: whatever is in the table belongs to the previous one, and so does any request still
    // in flight. Both go; the new store's calls come from the next Load Calls press.
    select.addEventListener('change', function () {
        if (inFlight !== null) {
            inFlight.abort();
            requestNumber++;
            settle();
        }
        status.textContent = '';
        message('Press Load Calls to list ' + storeName() + '’s latest calls.');
    });
}());
