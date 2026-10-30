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
 
       THE ORDER OF THE FIRST THREE STATEMENTS IS THE WHOLE TRICK, and it is not the obvious one.
 
       Both the player and the tab are opened SYNCHRONOUSLY, inside the click event, before any network
       call. A window opened later — in a promise callback, after an await — is no longer attributable
       to the user's gesture and every popup blocker refuses it.
 
       The player must come FIRST. `window.open()` consumes the transient activation, and
       `requestWindow()` then fails with `NotAllowedError: Document PiP requires user activation` —
       measured, not supposed. Requesting the player first and the tab second leaves both satisfied:
       the player opens and the tab is not blocked. Swapping these two lines back silently costs the
       player and nothing else, which is exactly the kind of regression nobody notices.
 
       The form stays a real form. With this script absent the POST still happens, the attempt is
       still recorded, and the server answers with a page carrying the link. */

    var ATTEMPT = '[data-ot-demo-url]';

    /* ---- the floating player ---------------------------------------------------------------------
 
       Pressing Demo URL opens the Order58 form in a second tab, and the trainee then works there. The
       recording they are transcribing lives in THIS tab, so once they switch, the audio is behind a tab
       they are no longer looking at — which is the whole difficulty this solves.
 
       Document Picture-in-Picture puts the player in a small always-on-top window owned by this
       document. It outlives a tab switch and keeps playing while the Order58 tab has focus. It does NOT
       outlive a navigation of THIS tab: the window belongs to this document and closes with it. That is
       measured behaviour, not a guess, and it is why the Order58 form opens in a second tab rather than
       in this one.
 
       The audio address is the one the row's own play button already uses — same route, same session,
       same authorization. Nothing new is exposed and no token is minted.
 
       `requestWindow()` needs transient user activation, so it is CALLED inside the click handler for
       the same reason `window.open` is. The window is furnished later, in the promise; only the call
       has to be attributable to the gesture. */

    function playerSupported() {
        // Secure context only — the API is simply absent on plain HTTP, which is the local dev case.
        return window.isSecureContext === true
            && 'documentPictureInPicture' in window
            && typeof window.documentPictureInPicture.requestWindow === 'function';
    }

    function recordingFor(button) {
        var row = button.closest('tr');
        var play = row ? row.querySelector('[data-a2t-play]') : null;

        return play ? play.getAttribute('data-a2t-play') : '';
    }

    function orderLabel(button) {
        var row = button.closest('tr');
        var id = row ? row.querySelector('.a2t-order-id') : null;

        return id ? id.textContent.trim() : '';
    }

    function clock(seconds) {
        if (!isFinite(seconds) || seconds < 0) {
            return '0:00';
        }

        var m = Math.floor(seconds / 60);
        var s = Math.floor(seconds % 60);

        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    /** The player's markup, built from the page's own classes so it wears the admin theme. */
    /**
     * The length this page already measured for that recording, or null.
     *
     * Written by the duration block at the foot of this file under the same key. Read here so the
     * player can print a real length the moment it opens, instead of the "0:00 / 0:00" that made a
     * recording still loading look like a broken one. Never a substitute for the element's own
     * metadata — it is replaced by `loadedmetadata` as soon as that arrives.
     */
    function knownDuration(url) {
        try {
            var value = window.sessionStorage.getItem('ot-duration:' + url);

            return value === null ? null : Number(value);
        } catch (e) {
            return null;
        }
    }

    function furnish(pip, url, label) {
        var doc = pip.document;

        // The stylesheets this page is already using, cloned in. They are served from this origin, so
        // `style-src 'self'` is satisfied and the window needs no inline CSS of its own.
        var sheets = document.querySelectorAll('link[rel="stylesheet"]');

        for (var i = 0; i < sheets.length; i++) {
            doc.head.appendChild(sheets[i].cloneNode(true));
        }

        doc.body.className = 'ot-pip';

        var audio = doc.createElement('audio');
        audio.src = url;
        audio.preload = 'metadata';

        var title = doc.createElement('div');
        title.className = 'ot-pip__title';
        title.textContent = label === '' ? 'Original call' : 'Original call ' + label;

        var play = doc.createElement('button');
        play.type = 'button';
        play.className = 'ot-pip__btn ot-pip__btn--play';
        play.textContent = 'Play';

        var replay = doc.createElement('button');
        replay.type = 'button';
        replay.className = 'ot-pip__btn';
        replay.textContent = 'Replay';

        var seek = doc.createElement('input');
        seek.type = 'range';
        seek.className = 'ot-pip__seek';
        seek.min = '0';
        seek.max = '100';
        seek.value = '0';
        seek.setAttribute('aria-label', 'Seek');

        var time = doc.createElement('span');
        time.className = 'ot-pip__time';
        time.textContent = '0:00 / 0:00';

        // The buffered extent, drawn under the seek bar. Honest by construction: it is painted from
        // `audio.buffered`, and when the browser reports no ranges it simply stays empty rather than
        // inventing a percentage.
        var buffered = doc.createElement('div');
        buffered.className = 'ot-pip__buffered';
        var bufferedFill = doc.createElement('div');
        bufferedFill.className = 'ot-pip__buffered-fill';
        buffered.appendChild(bufferedFill);

        // One line that says what the player is doing. Hidden whenever the answer is "playing nicely",
        // so normal playback gains no furniture.
        var status = doc.createElement('div');
        status.className = 'ot-pip__status';
        // role="status" carries an implicit aria-live="polite", so a screen reader announces
        // "Buffering audio..." without interrupting whatever it is already saying.
        status.setAttribute('role', 'status');
        status.hidden = true;
        var spinner = doc.createElement('span');
        spinner.className = 'ot-pip__spinner';
        var statusText = doc.createElement('span');
        statusText.className = 'ot-pip__status-text';
        var retry = doc.createElement('button');
        retry.type = 'button';
        retry.className = 'ot-pip__btn ot-pip__retry';
        retry.textContent = 'Retry';
        retry.hidden = true;
        status.appendChild(spinner);
        status.appendChild(statusText);
        status.appendChild(retry);

        var volume = doc.createElement('input');
        volume.type = 'range';
        volume.className = 'ot-pip__volume';
        volume.min = '0';
        volume.max = '1';
        volume.step = '0.05';
        volume.value = '1';
        volume.setAttribute('aria-label', 'Volume');

        var row1 = doc.createElement('div');
        row1.className = 'ot-pip__row';
        row1.appendChild(play);
        row1.appendChild(replay);
        row1.appendChild(time);

        var row2 = doc.createElement('div');
        row2.className = 'ot-pip__row ot-pip__row--seek';
        row2.appendChild(seek);
        row2.appendChild(buffered);

        var row3 = doc.createElement('div');
        row3.className = 'ot-pip__row';
        var vlabel = doc.createElement('span');
        vlabel.className = 'ot-pip__vol-label';
        vlabel.textContent = 'Vol';
        row3.appendChild(vlabel);
        row3.appendChild(volume);

        doc.body.appendChild(title);
        doc.body.appendChild(row1);
        doc.body.appendChild(row2);
        doc.body.appendChild(status);
        doc.body.appendChild(row3);
        doc.body.appendChild(audio);

        /*
         * What the player is doing, in one variable.
         *
         * 'loading'   nothing is playable yet
         * 'buffering' playable, but the next audio is not here
         * 'error'     the request failed; Retry is offered
         * ''          nothing to say — normal playback, or paused
         *
         * The browser resumes by itself once data arrives, so nothing below ever calls play() to
         * recover from buffering: doing so fights the element and is how playback jumps about.
         * `wanted` records the trainee's own intent, and is consulted only after a Retry.
         */
        var state = '';
        var wanted = false;
        var resumeAt = null;
        var measured = knownDuration(url);

        function say(next, text) {
            state = next;
            status.hidden = next === '';
            spinner.hidden = next === 'error' || next === '';
            retry.hidden = next !== 'error';
            statusText.textContent = text || '';
        }

        /** The length to print: the element's own once it has one, else what the page measured. */
        function total() {
            if (isFinite(audio.duration) && audio.duration > 0) {
                return audio.duration;
            }

            return measured !== null && isFinite(measured) && measured > 0 ? measured : 0;
        }

        function paintTime() {
            time.textContent = clock(audio.currentTime) + ' / ' + clock(total());
        }

        function paintBuffered() {
            var end = 0;
            var whole = total();

            // `buffered` can be empty, and can hold several ranges after seeking. The one that matters
            // is whichever range the playhead is inside.
            for (var i = 0; i < audio.buffered.length; i++) {
                if (audio.buffered.start(i) <= audio.currentTime && audio.buffered.end(i) >= end) {
                    end = audio.buffered.end(i);
                }
            }

            bufferedFill.style.width = whole > 0 ? String(Math.min(100, (end / whole) * 100)) + '%' : '0%';
        }

        // Deliberately NOT auto-played: the trainee is about to read an order form, and audio that
        // starts on its own is audio they have to find the controls for in a hurry.
        play.addEventListener('click', function () {
            if (audio.paused) {
                wanted = true;
                audio.play().catch(function () {
                    // A play() the browser refuses is not a loading failure; the element's own `error`
                    // event reports those. Nothing to say here.
                });
            } else {
                // An intentional pause. Nothing may start playback again on its own after this.
                wanted = false;
                audio.pause();
            }
        });

        replay.addEventListener('click', function () {
            wanted = true;
            audio.currentTime = 0;
            audio.play().catch(function () { });
        });

        /*
         * Retry: ask for the same recording again, and come back to where the trainee was.
         *
         * User-driven only — nothing here retries by itself, so a provider that is down cannot be
         * hammered by this page. `load()` re-requests the one URL; it does not open a second stream.
         */
        retry.addEventListener('click', function () {
            resumeAt = audio.currentTime > 0 ? audio.currentTime : null;
            say('loading', 'Loading audio...');
            audio.load();
        });

        audio.addEventListener('play', function () { play.textContent = 'Pause'; });
        audio.addEventListener('pause', function () { play.textContent = 'Play'; });

        audio.addEventListener('loadstart', function () {
            say('loading', 'Loading audio...');
            paintTime();
        });

        audio.addEventListener('loadedmetadata', function () {
            // The element's own length supersedes the page's measurement.
            measured = isFinite(audio.duration) && audio.duration > 0 ? audio.duration : measured;

            // Return to the position a Retry interrupted, once there is something to seek within.
            if (resumeAt !== null && isFinite(audio.duration) && audio.duration > resumeAt) {
                audio.currentTime = resumeAt;
            }

            resumeAt = null;
            paintTime();
        });

        // Playable. 'canplay' rather than 'canplaythrough': the latter waits for the whole file on
        // some browsers, which would leave the spinner up long after the trainee could have pressed
        // Play.
        audio.addEventListener('canplay', function () {
            if (state === 'loading' || state === 'buffering') {
                say('', '');
            }

            paintTime();
            paintBuffered();
        });

        // The element has run out of data — mid-play, or after a seek past what is buffered.
        audio.addEventListener('waiting', function () {
            say('buffering', 'Buffering audio...');
        });

        // Bytes are not arriving. The same message: from where the trainee sits it is the same
        // situation, and a second vocabulary would only puzzle them.
        audio.addEventListener('stalled', function () {
            if (state !== 'error') {
                say('buffering', 'Buffering audio...');
            }
        });

        audio.addEventListener('playing', function () {
            wanted = true;
            say('', '');
        });

        audio.addEventListener('seeked', function () {
            paintBuffered();
            paintTime();
        });

        audio.addEventListener('progress', paintBuffered);

        audio.addEventListener('ended', function () {
            wanted = false;
            say('', '');
        });

        /*
         * The one state the player could previously never leave.
         *
         * Without this the element failed silently and the window sat at "0:00 / 0:00" for ever, with
         * no way to tell a recording that was loading from one that never would. The endpoint answers
         * a failure with a status code and a plain-text body, which an <audio> element cannot show, so
         * the message says what the trainee can act on and Retry sits beside it.
         */
        audio.addEventListener('error', function () {
            say('error', 'Unable to load audio. Please try again.');
        });

        audio.addEventListener('timeupdate', function () {
            if (audio.duration > 0 && !seek.matches(':active')) {
                seek.value = String((audio.currentTime / audio.duration) * 100);
            }

            paintTime();
        });

        seek.addEventListener('input', function () {
            if (audio.duration > 0) {
                audio.currentTime = (Number(seek.value) / 100) * audio.duration;
            }
        });

        // The window opens before the first byte arrives, so it says so immediately rather than
        // showing a zero length it has not earned.
        say('loading', 'Loading audio...');
        paintTime();

        volume.addEventListener('input', function () {
            audio.volume = Number(volume.value);
        });
    }

    /** Opened for its side effect; the promise is kept only so a refused attempt can close it again. */
    function openPlayer(button) {
        var url = recordingFor(button);

        if (url === '' || !playerSupported()) {
            return null;
        }

        return window.documentPictureInPicture
            .requestWindow({ width: 320, height: 190 })
            .then(function (pip) {
                furnish(pip, url, orderLabel(button));

                return pip;
            })
            .catch(function () {
                // Refused, or unavailable after all. The second tab still opens; the row's own play
                // button is still there. Nothing is said about it, because nothing is broken.
                return null;
            });
    }

    function closePlayer(player) {
        if (!player) {
            return;
        }

        player.then(function (pip) {
            if (pip && typeof pip.close === 'function') {
                pip.close();
            }
        });
    }


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

        // FIRST. See the note above. The player is requested in the same breath and for the same
        // reason: both need the user's gesture, and neither may wait for the network.
        var player = openPlayer(button);
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
            // sitting there suggesting something is loading, nor a player for an order the operator
            // was not given.
            if (tab) {
                tab.close();
            }

            closePlayer(player);

            message(button, typeof result.data.error === 'string'
                ? result.data.error
                : 'The demo page could not be opened. Please try again.');
            release(button);
        }).catch(function () {
            if (tab) {
                tab.close();
            }

            closePlayer(player);
            message(button, 'The demo page could not be opened. Please try again.');
            release(button);
        });
    });
}());

/* ---- The length of each Case Order recording, inside its Play button ------------------------------

   Nothing stores a Case Order recording's length: the audio is streamed from the provider on demand.
   So it is read here from the WAV header — the first 4 KB, asked for with a Range request on the same
   address Play uses — and computed as data bytes over the byte rate.

   Deliberately frugal, because every read makes the server fetch that recording from the provider:
   only rows that are on screen are asked about (a closed "N recordings" list waits until it is opened),
   one at a time, each address once, and the answer is kept for the browser session. The players are not
   touched — this uses fetch, never a media element — so Play, Pause and Download behave exactly as
   before. A length that cannot be read is hidden, leaving the Play icon on its own. */

(function () {
    'use strict';

    var labels = document.querySelectorAll('[data-ot-duration]');

    if (labels.length === 0 || typeof window.fetch !== 'function') {
        return;
    }

    var HEADER_BYTES = 4096;
    var CACHE_PREFIX = 'ot-duration:';
    var queue = [];
    var asked = {};
    var busy = false;

    function two(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /** `02:35`, or `01:12:30` from an hour up. */
    function clock(seconds) {
        var whole = Math.round(seconds);
        var hours = Math.floor(whole / 3600);
        var rest = two(Math.floor((whole % 3600) / 60)) + ':' + two(whole % 60);

        return hours > 0 ? two(hours) + ':' + rest : rest;
    }

    function remembered(url) {
        try {
            var value = window.sessionStorage.getItem(CACHE_PREFIX + url);

            return value === null ? null : Number(value);
        } catch (e) {
            return null;
        }
    }

    function remember(url, seconds) {
        try {
            window.sessionStorage.setItem(CACHE_PREFIX + url, String(seconds));
        } catch (e) {
            // Storage refused or full: the length is simply read again next time.
        }
    }

    /** Every label for this address — the first recording is listed twice when an order has several. */
    function show(url, seconds) {
        Array.prototype.forEach.call(labels, function (label) {
            if (label.getAttribute('data-ot-duration') !== url) {
                return;
            }

            if (seconds === null) {
                label.setAttribute('data-ot-duration-state', 'unknown');

                return;
            }

            label.textContent = clock(seconds);
            label.setAttribute('data-ot-duration-state', 'known');
        });
    }

    function tag(view, offset) {
        var text = '';

        for (var i = 0; i < 4; i++) {
            text += String.fromCharCode(view.getUint8(offset + i));
        }

        return text;
    }

    /**
     * Seconds of audio, from a RIFF/WAVE header: the `data` chunk's size over the `fmt ` byte rate.
     *
     * A recorder that streamed the file may leave the data size at 0 or 0xFFFFFFFF; the file's total
     * size from Content-Range is used instead then, which is what the size would have been.
     */
    function lengthOf(buffer, total) {
        var view = new DataView(buffer);

        if (view.byteLength < 12 || tag(view, 0) !== 'RIFF' || tag(view, 8) !== 'WAVE') {
            return null;
        }

        var byteRate = 0;
        var offset = 12;

        while (offset + 8 <= view.byteLength) {
            var id = tag(view, offset);
            var size = view.getUint32(offset + 4, true);

            if (id === 'fmt ' && offset + 20 <= view.byteLength) {
                byteRate = view.getUint32(offset + 16, true);
            }

            if (id === 'data') {
                var available = total > 0 ? total - (offset + 8) : 0;
                var bytes = size > 0 && size !== 0xFFFFFFFF && (available === 0 || size <= available) ? size : available;

                return byteRate > 0 && bytes > 0 ? bytes / byteRate : null;
            }

            offset += 8 + size + (size % 2);
        }

        return null;
    }

    /**
     * The length read out of the file's own RIFF header.
     *
     * Kept exactly as it was, and still the authority: the provider does not honour `Range`, so this
     * costs a whole recording. It now runs only when the cheap question below cannot be answered —
     * a format the size shortcut does not speak for — which is the case it was always right for.
     */
    function readHeader(url) {
        return window.fetch(url, {
            headers: { Range: 'bytes=0-' + (HEADER_BYTES - 1) },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    // Read the short refusal to its end: an unread body keeps the request open, and
                    // the page never settles.
                    return response.text().then(function () {
                        return null;
                    });
                }

                var range = /\/(\d+)$/.exec(response.headers.get('Content-Range') || '');
                var total = range ? Number(range[1]) : Number(response.headers.get('Content-Length') || 0);

                return response.arrayBuffer().then(function (buffer) {
                    return lengthOf(buffer, total);
                });
            })
            .catch(function () {
                return null;
            });
    }

    /**
     * The length the server worked out from the recording's SIZE, without fetching the audio.
     *
     * Null means "could not tell" — a 409, or anything unexpected — and never "no recording". The
     * caller falls back to the header read, so being unsure costs what this page cost before.
     */
    function askSize(url) {
        return window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'duration=1', {
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    // Drain the short refusal, or the request stays open.
                    return response.text().then(function () {
                        return null;
                    });
                }

                return response.json().then(function (data) {
                    var seconds = Number(data && data.seconds);

                    return isFinite(seconds) && seconds > 0 ? seconds : null;
                });
            })
            .catch(function () {
                return null;
            });
    }

    function next() {
        if (busy || queue.length === 0) {
            return;
        }

        busy = true;
        var url = queue.shift();

        // The cheap question first; the header read only when it cannot answer.
        askSize(url)
            .then(function (seconds) {
                return seconds !== null ? seconds : readHeader(url);
            })
            .then(function (seconds) {
                // `url` here is always the ORIGINAL address — never the `?duration=1` form. Both the
                // label lookup and the session cache are keyed on it, and a suffixed key would quietly
                // match no label and poison the cache.
                if (seconds !== null) {
                    remember(url, seconds);
                }

                show(url, seconds);
            })
            .catch(function () {
                show(url, null);
            })
            .then(function () {
                busy = false;
                next();
            });
    }

    function want(url) {
        if (!url || asked[url]) {
            return;
        }

        asked[url] = true;
        var cached = remembered(url);

        if (cached !== null && isFinite(cached) && cached > 0) {
            show(url, cached);

            return;
        }

        queue.push(url);
        next();
    }

    if (!('IntersectionObserver' in window)) {
        Array.prototype.forEach.call(labels, function (label) {
            want(label.getAttribute('data-ot-duration'));
        });

        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                observer.unobserve(entry.target);
                want(entry.target.getAttribute('data-ot-duration'));
            }
        });
    }, { rootMargin: '200px 0px' });

    Array.prototype.forEach.call(labels, function (label) {
        observer.observe(label);
    });
}());
