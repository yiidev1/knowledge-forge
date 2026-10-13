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
        row2.className = 'ot-pip__row';
        row2.appendChild(seek);

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
        doc.body.appendChild(row3);
        doc.body.appendChild(audio);

        // Deliberately NOT auto-played: the trainee is about to read an order form, and audio that
        // starts on its own is audio they have to find the controls for in a hurry.
        play.addEventListener('click', function () {
            if (audio.paused) {
                audio.play();
            } else {
                audio.pause();
            }
        });

        replay.addEventListener('click', function () {
            audio.currentTime = 0;
            audio.play();
        });

        audio.addEventListener('play', function () { play.textContent = 'Pause'; });
        audio.addEventListener('pause', function () { play.textContent = 'Play'; });

        audio.addEventListener('timeupdate', function () {
            if (audio.duration > 0 && !seek.matches(':active')) {
                seek.value = String((audio.currentTime / audio.duration) * 100);
            }

            time.textContent = clock(audio.currentTime) + ' / ' + clock(audio.duration);
        });

        audio.addEventListener('loadedmetadata', function () {
            time.textContent = clock(0) + ' / ' + clock(audio.duration);
        });

        seek.addEventListener('input', function () {
            if (audio.duration > 0) {
                audio.currentTime = (Number(seek.value) / 100) * audio.duration;
            }
        });

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
