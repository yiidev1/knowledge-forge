/* Store-audio page only: the existing upload POST plus read-only conversion status polling. */

/**
 * `ProcessingStage` in English, once for this page.
 *
 * Two things on this page follow a job: the upload form at the top, and the Update Audio dialog watching
 * a replacement it just queued. They are separate scopes in this file, and a second copy of these seven
 * strings would be two vocabularies for one enum — found to have drifted only when a reader noticed the
 * same stage worded two ways.
 *
 * Deliberately global, like `window.KFReviewTurns`. There is no module loader here.
 */
window.KFAudioStages = {
    QUEUED: 'Waiting for the transcription worker',
    CLAIMED: 'Starting conversion',
    CONVERTING: 'Preparing audio for transcription',
    TRANSCRIBING: 'Transcribing audio to text',
    DIARIZING: 'Separating speakers',
    MAPPING_SPEAKERS: 'Identifying speakers',
    SAVING: 'Saving the transcript'
};

(function () {
    'use strict';

    var forms = Array.from(document.querySelectorAll('[data-a2t-upload]'));
    if (!forms.length || !window.XMLHttpRequest || !window.FormData || !window.DOMParser || !window.fetch || !window.AbortController) {
        return; // The original server-rendered form submission remains the fallback.
    }
    var uploading = false;
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && uploading) {
            // Back from the result must not restore disabled controls and a stopped polling loop.
            window.location.reload();
        }
    });
    var stages = window.KFAudioStages;

    forms.forEach(function (form) {
        var input = form.querySelector('.a2t-upload-input');
        var fileInfo = form.querySelector('[data-a2t-file]');
        var feedback = form.querySelector('[data-a2t-feedback]');
        var stateLabel = form.querySelector('[data-a2t-state-label]');
        var uploadStep = form.querySelector('[data-a2t-step="upload"]');
        var status = form.querySelector('[data-a2t-upload-status]');
        var percent = form.querySelector('[data-a2t-upload-percent]');
        var progress = form.querySelector('[data-a2t-upload-progress]');
        var conversionStep = form.querySelector('[data-a2t-step="conversion"]');
        var conversionStatus = form.querySelector('[data-a2t-conversion-status]');
        var conversionPercent = form.querySelector('[data-a2t-conversion-percent]');
        var conversionProgress = form.querySelector('[data-a2t-conversion-progress]');
        var error = form.querySelector('[data-a2t-upload-error]');
        var result = form.querySelector('[data-a2t-upload-result]');
        var button = form.querySelector('.a2t-upload-submit');
        var buttonLabel = button.textContent;
        var timer = null;
        var statusRequest = null;
        var stopped = false;

        function state(value, label) {
            feedback.hidden = value === 'idle';
            uploadStep.hidden = value === 'error';
            conversionStep.hidden = value === 'error';
            feedback.dataset.a2tState = value;
            stateLabel.textContent = label;
        }

        input.addEventListener('change', function () {
            var file = input.files[0];
            fileInfo.hidden = !file;
            fileInfo.textContent = file ? file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' MB' : '';
            state('idle', 'Ready');
            status.textContent = file ? 'Ready to upload' : 'Awaiting audio file';
            percent.textContent = '0%';
            progress.value = 0;
            uploadStep.dataset.state = 'pending';
            conversionStep.dataset.state = 'pending';
            conversionProgress.value = 0;
            conversionPercent.textContent = 'Pending';
            conversionStatus.textContent = 'Starts after upload';
            error.hidden = true;
            result.hidden = true;
        });

        window.addEventListener('pagehide', function () {
            stopped = true;
            clearTimeout(timer);
            if (statusRequest) {
                statusRequest.abort();
            }
        });

        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }
            event.preventDefault();
            if (uploading) {
                return;
            }

            // Keep mode, file, CSRF, provider and paid opt-in exactly as the normal POST sends them.
            var payload = new FormData(form);
            var xhr = new XMLHttpRequest();
            var disabledControls = [];
            uploading = true;
            stopped = false;
            forms.forEach(function (uploadForm) {
                Array.from(uploadForm.elements).forEach(function (control) {
                    if (!control.disabled) {
                        control.disabled = true;
                        disabledControls.push(control);
                    }
                });
            });
            error.hidden = true;
            result.hidden = true;
            progress.value = 0;
            percent.textContent = '0%';
            uploadStep.dataset.state = 'active';
            conversionStep.dataset.state = 'pending';
            conversionProgress.value = 0;
            conversionPercent.textContent = 'Pending';
            conversionStatus.textContent = 'Starts after upload';
            state('uploading', 'Uploading');
            status.textContent = 'Uploading audio…';
            button.textContent = 'Uploading…';

            function unlock() {
                uploading = false;
                disabledControls.forEach(function (control) { control.disabled = false; });
                // Match a server-rendered response: the paid opt-in is never sticky.
                form.elements.namedItem('generate_ai_audio').checked = false;
                button.textContent = buttonLabel;
            }

            function showError(message) {
                error.textContent = message;
                error.hidden = false;
            }

            function failUpload(message) {
                unlock();
                state('error', 'Upload interrupted');
                uploadStep.dataset.state = 'error';
                // Retain the last measured transfer progress instead of falsely resetting to 0%.
                status.textContent = 'Upload needs attention';
                showError(message);
                error.tabIndex = -1;
                error.focus();
            }

            function trackConversion(statusUrl, destination, interval, doneUrl) {
                // Same-origin or not at all, the rule the status URL already follows. `destination` is
                // the page this upload landed on and stays the fallback, so a response that somehow
                // named an external URL changes nothing about where this ends up.
                var finish = doneUrl && doneUrl.origin === window.location.origin ? doneUrl : destination;

                uploadStep.dataset.state = 'complete';
                progress.value = 100;
                percent.textContent = '100%';
                status.textContent = 'Audio file uploaded successfully';
                result.href = destination.href;
                result.hidden = false;
                conversionStep.dataset.state = 'active';
                conversionProgress.removeAttribute('value');
                conversionStatus.textContent = 'Checking conversion status…';
                conversionPercent.textContent = 'Waiting';
                state('converting', 'Converting');
                button.textContent = 'Converting…';

                function unavailable(message) {
                    // The recording was accepted. Never automatically resubmit it after a status error.
                    stopped = true;
                    state('error', 'Status unavailable');
                    conversionStep.dataset.state = 'error';
                    conversionProgress.value = 0;
                    conversionPercent.textContent = 'Unavailable';
                    conversionStatus.textContent = 'Open the conversion to check its status';
                    button.textContent = 'Recording uploaded';
                    showError(message);
                }

                function poll() {
                    if (stopped) {
                        return;
                    }
                    statusRequest = new AbortController();
                    var timeout = setTimeout(function () { statusRequest.abort(); }, 15000);
                    fetch(statusUrl.href, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: statusRequest.signal
                    })
                        .then(function (response) {
                            if (response.redirected || response.status === 401 || response.status === 403) {
                                unavailable('Your session may have expired. Open the conversion to sign in and continue.');
                                return null;
                            }
                            if (response.status === 404) {
                                unavailable('This conversion is no longer available. Open the conversion for details.');
                                return null;
                            }
                            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
                                throw new Error('Status temporarily unavailable');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            if (stopped || !data) {
                                return;
                            }
                            if (!['QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED'].includes(data.status)) {
                                throw new Error('Unrecognized status');
                            }
                            error.hidden = true;
                            if (data.status === 'COMPLETED') {
                                // `data-a2t-stay` marks a form inside a dialog on a listing page: the
                                // new recording belongs in the table behind it, so this reloads and
                                // the order it joined is there. Without the attribute nothing changes
                                // — the standalone upload pages still open the correction page.
                                var stay = form.hasAttribute('data-a2t-stay');
                                stopped = true;
                                conversionStep.dataset.state = 'complete';
                                conversionProgress.value = 100;
                                conversionPercent.textContent = '100%';
                                conversionStatus.textContent = stay
                                    ? 'Conversion complete · refreshing this page…'
                                    : 'Conversion complete · opening result…';
                                state('complete', 'Complete');
                                button.textContent = 'Conversion complete';
                                // On to the correction page for this job, and do not touch the
                                // listing. A conversion with nothing to correct is handed back to the
                                // detail page by that route, which is where this used to stop anyway.
                                timer = setTimeout(function () {
                                    if (stay) {
                                        window.location.reload();
                                        return;
                                    }
                                    window.location.assign(finish.href);
                                }, 1000);
                                return;
                            }
                            if (data.status === 'FAILED') {
                                stopped = true;
                                conversionStep.dataset.state = 'error';
                                conversionProgress.value = 0;
                                conversionPercent.textContent = 'Failed';
                                conversionStatus.textContent = 'Conversion could not be completed';
                                state('error', 'Conversion failed');
                                showError('The file was uploaded, but conversion failed. Open the conversion for details.');
                                unlock();
                                return;
                            }
                            state('converting', data.status === 'QUEUED' ? 'Queued' : 'Converting');
                            conversionPercent.textContent = data.status === 'QUEUED' ? 'Waiting' : 'In progress';
                            conversionStatus.textContent = data.status === 'QUEUED'
                                ? stages.QUEUED : (stages[data.stage] || 'Processing your recording');
                            timer = setTimeout(poll, interval);
                        })
                        .catch(function () {
                            if (stopped) {
                                return;
                            }
                            state('reconnecting', 'Reconnecting');
                            conversionPercent.textContent = 'Reconnecting';
                            conversionStatus.textContent = 'Checking status again shortly…';
                            showError('Connection interrupted. Your recording remains uploaded; conversion may still be running.');
                            timer = setTimeout(poll, Math.max(interval, 5000));
                        })
                        .finally(function () {
                            clearTimeout(timeout);
                            statusRequest = null;
                        });
                }
                poll();
            }

            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    var value = Math.min(100, Math.round(event.loaded / event.total * 100));
                    progress.value = value;
                    percent.textContent = value + '%';
                } else {
                    progress.removeAttribute('value');
                    percent.textContent = 'Uploading';
                }
            });
            xhr.upload.addEventListener('load', function () {
                progress.value = 100;
                percent.textContent = '100%';
                state('accepting', 'Confirming');
                status.textContent = 'File sent · confirming upload…';
                button.textContent = 'Accepting recording…';
            });
            xhr.addEventListener('load', function () {
                var destination = new URL(xhr.responseURL || form.action, window.location.href);
                var submittedTo = new URL(form.action, window.location.href);
                var response = new DOMParser().parseFromString(xhr.responseText, 'text/html');
                // Both redirect destinations refer to the child job. Preserve any deployment prefix.
                var jobPath = destination.pathname.match(/^(.*\/audio-to-text\/job\/[0-9a-f]{32})(?:\/review)?\/?$/);
                if (destination.origin === window.location.origin && jobPath) {
                    var pendingJob = response.querySelector('[data-a2t-poll]');
                    var statusUrl = new URL(pendingJob ? pendingJob.dataset.a2tPoll : jobPath[1] + '/status', destination);
                    // Where a finished conversion opens: the correction page, named by the server in
                    // `data-a2t-done` so no host, deployment prefix or job token is assembled here.
                    // The fallback follows the same shape as the status one above, for a response that
                    // carried no pending job — a job that finished before this ever polled.
                    var doneUrl = new URL(
                        pendingJob && pendingJob.dataset.a2tDone ? pendingJob.dataset.a2tDone : jobPath[1] + '/review',
                        destination
                    );
                    var interval = Math.max(2000, pendingJob ? parseInt(pendingJob.dataset.a2tInterval, 10) || 2000 : 2000);
                    if (statusUrl.origin === window.location.origin) {
                        trackConversion(statusUrl, destination, interval, doneUrl);
                        return;
                    }
                }
                if (xhr.status >= 200 && xhr.status < 300 && destination.href !== submittedTo.href) {
                    // Preserve the existing login/other redirect if this was not a created job.
                    window.location.assign(destination.href);
                    return;
                }
                var messages = Array.from(response.querySelectorAll('.a2t-uploads .field__error, .alert--error p'))
                    .map(function (node) { return node.textContent.trim(); })
                    .filter(function (message, index, all) { return message && all.indexOf(message) === index; });
                failUpload(messages.join(' ') || (xhr.status === 413
                    ? 'The server could not accept this file because it is too large. Choose a smaller recording.'
                    : 'The server could not confirm this upload. Check the conversions below before trying again.'));
            });
            xhr.addEventListener('error', function () {
                failUpload('Connection interrupted. Check the conversions below before trying again; the server may have received the file.');
            });
            xhr.addEventListener('abort', function () {
                failUpload('Upload interrupted. Check the conversions below before trying again.');
            });
            try {
                xhr.open('POST', form.action);
                xhr.send(payload);
            } catch (e) {
                failUpload('The upload could not be started. Please try again.');
            }
        });
    });
}());

/* ------------------------------------------------------------------------------------------------
 * Store-audio page only: the grouped conversions table.
 *
 * A row on this page is an **order**, and everything an administrator needs to do with one happens
 * here: play a recording, read what the machine heard, correct it, generate the clean audio. This
 * block is what opens those dialogs and what fills them.
 *
 * Three rules it keeps, all of them load-bearing:
 *
 * 1. **It composes no URL.** Every address is printed into the control that uses it by the template,
 *    which knows the deployment prefix and the route shapes. Nothing here assembles a path from an id.
 * 2. **It decides nothing about the domain.** Which role a Move targets, whether a merge is legal,
 *    which `output_type` a Caller recording generates, whether anything may be generated at all — the
 *    server answers each of those and this echoes the answer back. CALLER is not CUSTOMER, and the one
 *    place that could wrongly equate them is the one place that never learns they exist.
 * 3. **It writes through the existing routes.** The corrections in the Details dialog POST to the same
 *    seven endpoints the full review page posts to, so there is exactly one path into
 *    `ReviewConversationService` and one audit trail, however the transcript was opened.
 * ---------------------------------------------------------------------------------------------- */
(function () {
    'use strict';

    var dialogs = Array.from(document.querySelectorAll('[data-a2t-dialog]'));
    if (
        !dialogs.length
        || !window.fetch || !window.Map || !window.Audio || !window.URLSearchParams
        || !window.FormData || !window.KFReviewTurns
        || typeof document.createElement('dialog').showModal !== 'function'
    ) {
        // Every control this block owns has a server-rendered way round it — the per-job pages are all
        // still routed — so doing nothing leaves the page usable rather than broken.
        return;
    }

    /* ---- Helpers -------------------------------------------------------------------------- */

    function empty(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    /** Elements are built and filled with textContent; no markup from a response is ever parsed. */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null && text !== '') {
            node.textContent = text;
        }
        return node;
    }

    function say(node, message) {
        node.textContent = message;
        node.hidden = false;
    }

    /**
     * A failure the reader can act on, rather than a blank panel.
     *
     * A dialog that fetched and lost is indistinguishable from one that is still fetching unless it
     * says so, and a reader with no Retry has only the close button and a reload of the whole page.
     */
    function fail(node, message, retry) {
        empty(node);
        node.appendChild(document.createTextNode(message + ' '));
        var again = el('button', 'btn btn--sm', 'Retry');
        again.type = 'button';
        again.addEventListener('click', retry);
        node.appendChild(again);
        node.hidden = false;
    }

    function quiet(node) {
        node.textContent = '';
        node.hidden = true;
    }

    /** Milliseconds as a length of listening: `1:04`, not `64000`. */
    function clock(ms) {
        if (typeof ms !== 'number' || !isFinite(ms) || ms < 0) {
            return '';
        }
        var whole = Math.round(ms / 1000);
        return Math.floor(whole / 60) + ':' + ('0' + (whole % 60)).slice(-2);
    }

    function seconds(value) {
        return typeof value === 'number' && value > 0 ? clock(value * 1000) : '';
    }

    /* ---- One page, one sound -------------------------------------------------------------- */

    // Sixty native <audio> players is sixty pieces of browser chrome and sixty preloads, so the table
    // prints buttons and this plays them. Keeping the elements means pressing play again resumes
    // rather than restarting, and keeping only one `playing` is what makes "one at a time" true.
    var players = new Map();
    var playing = null;

    function mark(button, on) {
        if (on) {
            button.setAttribute('data-playing', '');
        } else {
            button.removeAttribute('data-playing');
        }
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    function silence() {
        if (playing === null) {
            return;
        }
        playing.audio.pause();
        mark(playing.button, false);
        playing = null;
    }

    /** Buttons inside a dialog are replaced every time it opens; their players go with them. */
    function forgetDetached() {
        var gone = [];
        players.forEach(function (audio, button) {
            if (!button.isConnected) {
                audio.pause();
                gone.push(button);
            }
        });
        gone.forEach(function (button) { players.delete(button); });
    }

    function playerFor(button) {
        var audio = players.get(button);
        if (audio) {
            return audio;
        }
        audio = new Audio();
        audio.preload = 'none';
        audio.src = button.getAttribute('data-a2t-play');
        audio.addEventListener('ended', function () {
            mark(button, false);
            if (playing !== null && playing.button === button) {
                playing = null;
            }
        });
        players.set(button, audio);
        return audio;
    }

    function toggle(button) {
        if (playing !== null && playing.button === button) {
            silence();
            return;
        }
        silence();
        var audio = playerFor(button);
        mark(button, true);
        playing = { button: button, audio: audio };
        var started = audio.play();
        if (started && typeof started.catch === 'function') {
            started.catch(function () {
                if (playing !== null && playing.button === button) {
                    silence();
                }
                button.classList.add('is-broken');
                button.title = 'This recording could not be played.';
            });
        }
    }

    /* ---- Dialogs -------------------------------------------------------------------------- */

    function dialogOf(node) {
        return node ? node.closest('[data-a2t-dialog]') : null;
    }

    /** Whether this dialog is in the browser's top layer, rather than merely visible. */
    function isModal(dialog) {
        try {
            return dialog.matches(':modal');
        } catch (e) {
            return false;
        }
    }

    function openDialog(dialog) {
        if (!dialog) {
            return;
        }

        if (dialog.open) {
            if (isModal(dialog)) {
                return;
            }
            // Open, but not in the top layer — a server-rendered `open` attribute is the no-script
            // fallback and gets no backdrop. `showModal()` on an already-open dialog throws
            // InvalidStateError, so it is closed first and reopened properly rather than left as a
            // block sitting in the page.
            dialog.close();
        }

        try {
            dialog.showModal();
        } catch (e) {
            // Last resort. Visible and usable, without the backdrop or the Escape handling.
            dialog.setAttribute('open', '');
        }
    }

    function closeDialog(dialog) {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    dialogs.forEach(function (dialog) {
        // Audio must not go on playing behind a dialog that is no longer on screen.
        dialog.addEventListener('close', function () {
            silence();
            forgetDetached();
        });
        // A server-rendered `open` is a non-modal dialog — the state the page falls back to without
        // this script. Upgrade it so the backdrop and Escape behave like every other dialog here.
        if (dialog.hasAttribute('open')) {
            openDialog(dialog);
        }
    });

    /* ---- Reading the server's answers ----------------------------------------------------- */

    function load(url) {
        return fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            if (response.redirected || response.status === 401 || response.status === 403) {
                throw new Error('Your session may have expired. Reload the page and sign in again.');
            }
            if (response.status === 404) {
                throw new Error('This is no longer available. Reload the page to see what is there now.');
            }
            if (!response.ok) {
                throw new Error('The server could not answer just now. Try again in a moment.');
            }
            return response.json();
        });
    }

    /* ---- Earlier recordings of the same type ---------------------------------------------- */

    var historyBody = document.querySelector('[data-a2t-history-body]');
    var historyDialog = dialogOf(historyBody);

    function openHistory(button) {
        var source = document.querySelector(
            '[data-a2t-history-for="' + button.getAttribute('data-a2t-history') + '"]'
        );
        if (!source || !historyBody) {
            return;
        }
        // Already on the page — every earlier recording was rendered with the row and only folded
        // away. Cloning it asks the server nothing to learn something this page already knows.
        var clone = source.cloneNode(true);
        clone.hidden = false;
        clone.removeAttribute('data-a2t-history-for');
        empty(historyBody);
        historyBody.appendChild(clone);
        openDialog(historyDialog);
    }

    /* ---- One conversation, drawn one way --------------------------------------------------- */

    // Both dialogs show the same thing — a call, as it was spoken — so both draw it with the same
    // function and the same classes the correction page and the conversation page already use
    // (`.a2t-turn`, `.a2t-bubble`, `.a2t-turn__who|text|meta`). Details then hangs controls off the
    // bubble; Original transcript does not, because nothing there can be corrected.
    //
    // Nothing here decides who spoke. `side`, `label` and `confirmed` all arrive settled: the server
    // withholds Agent and Customer until the separation is publishable and sends a neutral speaker
    // name instead, and a bubble that chose its own side would be choosing who the agent is.
    function bubble(turn) {
        var row = document.createElement('div');
        row.className = 'a2t-turn a2t-turn--' + turn.side + (turn.confirmed ? '' : ' a2t-turn--unconfirmed');
        if (turn.role) {
            // Only the correction dialog sends a role, and only a published one is tinted — the
            // stylesheet's `:not(.a2t-turn--unconfirmed)` is what enforces that, not this line.
            row.setAttribute('data-a2t-role', turn.role);
        }

        var body = el('div', 'a2t-bubble');
        body.appendChild(el('span', 'a2t-turn__who', turn.label));
        var text = el('span', 'a2t-turn__text', turn.display || turn.text);
        body.appendChild(text);

        if (turn.time || turn.delay || turn.edited) {
            var meta = el('span', 'a2t-turn__meta');
            if (turn.time) {
                var time = el('span', 'a2t-turn__time', turn.time);
                if (turn.approx) {
                    time.title = 'Approximate: this boundary was set by hand, so both halves keep '
                        + 'the original turn’s timing.';
                }
                meta.appendChild(time);
            }
            if (turn.delay) {
                meta.appendChild(el('span', 'a2t-turn__delay', turn.delay));
            }
            if (turn.edited) {
                meta.appendChild(el('span', 'a2t-turn__flag', 'edited'));
            }
            body.appendChild(meta);
        }

        row.appendChild(body);
        return { row: row, body: body, text: text };
    }

    /* ---- Original transcript: machine output, read-only ------------------------------------ */

    var transcriptTabs = document.querySelector('[data-a2t-transcript-tabs]');
    var transcriptBody = document.querySelector('[data-a2t-transcript-body]');
    var transcriptScroll = document.querySelector('[data-a2t-transcript-scroll]');
    var transcriptStatus = document.querySelector('[data-a2t-transcript-status]');
    var transcriptMeta = document.querySelector('[data-a2t-transcript-meta]');
    var transcriptDialog = dialogOf(transcriptBody);

    function showTranscript(entry, tab) {
        Array.from(transcriptTabs.children).forEach(function (other) {
            other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
        });

        var meta = [entry.label, entry.provider, seconds(entry.duration), entry.uploadedAt]
            .filter(function (part) { return part; })
            .join(' · ');
        transcriptMeta.textContent = meta;

        empty(transcriptScroll);

        if (entry.segments.length) {
            var thread = el('div', 'a2t-thread');
            entry.segments.forEach(function (segment) {
                // The transcript's own field names, mapped onto the shared turn shape. `speaker` is
                // whatever the server called this voice, neutral name included.
                thread.appendChild(bubble({
                    label: segment.speaker,
                    text: segment.text,
                    confirmed: segment.speakerConfirmed,
                    side: segment.side,
                    time: segment.time,
                    delay: segment.delay,
                    edited: segment.edited
                }).row);
            });
            transcriptScroll.appendChild(thread);
        } else {
            // A completed recording whose speakers were never separated. The words are real; no
            // speaker is invented for them, so it is one neutral passage rather than a conversation.
            transcriptScroll.appendChild(el(
                'p',
                'a2t-meta-line',
                'Speakers were not separated for this recording, so it is shown as one passage.'
            ));
            var plain = el('div', 'a2t-thread');
            var row = el('div', 'a2t-turn a2t-turn--neutral');
            var passage = el('div', 'a2t-bubble');
            passage.appendChild(el('span', 'a2t-turn__text a2t-turn__text--plain', entry.plainText));
            row.appendChild(passage);
            plain.appendChild(row);
            transcriptScroll.appendChild(plain);
        }

        transcriptBody.hidden = false;
        transcriptScroll.scrollTop = 0;
    }

    function openTranscripts(button) {
        openDialog(transcriptDialog);
        empty(transcriptTabs);
        empty(transcriptScroll);
        transcriptTabs.hidden = true;
        transcriptBody.hidden = true;
        var order = button.getAttribute('data-a2t-order');
        transcriptMeta.textContent = order ? 'Order ' + order : 'No order id';
        say(transcriptStatus, 'Loading transcript…');

        load(button.getAttribute('data-a2t-transcripts')).then(function (data) {
            if (!data.transcripts.length) {
                say(transcriptStatus, 'No original transcript is available for this order yet.');
                return;
            }
            quiet(transcriptStatus);
            data.transcripts.forEach(function (entry, position) {
                var tab = el('button', 'a2t-tab', entry.label);
                tab.type = 'button';
                tab.setAttribute('role', 'tab');
                // Set at creation so a tab that has not been selected yet still says so, rather than
                // carrying no state until the first time somebody clicks another one.
                tab.setAttribute('aria-selected', 'false');
                tab.addEventListener('click', function () { showTranscript(entry, tab); });
                transcriptTabs.appendChild(tab);
                if (position === 0) {
                    showTranscript(entry, tab);
                }
            });
            // One recording needs no tab bar; two or three do.
            transcriptTabs.hidden = data.transcripts.length < 2;
        }).catch(function (error) {
            fail(transcriptStatus, error.message, function () { openTranscripts(button); });
        });
    }

    /* ---- Generate text to audio ------------------------------------------------------------ */

    var ttsForm = document.querySelector('[data-a2t-tts-form]');
    var ttsOptions = document.querySelector('[data-a2t-tts-options]');
    var ttsOutput = document.querySelector('[data-a2t-tts-output]');
    var ttsHash = document.querySelector('[data-a2t-tts-hash]');
    var ttsSubmit = document.querySelector('[data-a2t-tts-submit]');
    var ttsStatus = document.querySelector('[data-a2t-tts-status]');
    var ttsMeta = document.querySelector('[data-a2t-tts-meta]');
    var ttsDialog = dialogOf(ttsForm);
    var notice = document.querySelector('[data-a2t-notice]');
    var ttsRow = null;     // the row the open dialog belongs to
    var ttsUrl = null;     // that row's options endpoint, re-read after a generation
    var ttsPoll = null;
    var ttsBusy = false;

    /** What a page load's flash would have said, for an action that deliberately did not reload. */
    function announce(message, kind) {
        if (!notice) {
            return;
        }
        notice.className = 'a2t-notice alert alert--' + kind;
        notice.textContent = message;
        notice.hidden = false;
    }

    /**
     * Redraw one row's Text to Audio cell from the server's own answer.
     *
     * The states and the play URL are the slot's, computed where the page computes them, so nothing
     * here decides whether a recording is Queued or Ready — it only puts the words on screen.
     */
    function paintTtsCell(row, options) {
        var cell = row ? row.querySelector('.a2t-tts-list') : null;

        if (!cell) {
            return false;
        }

        empty(cell);
        var working = false;

        options.forEach(function (option) {
            cell.appendChild(el('span', 'a2t-tts__label', option.label));

            if (option.playUrl) {
                var play = el('button', 'a2t-play a2t-play--sm');
                play.type = 'button';
                play.setAttribute('data-a2t-play', option.playUrl);
                play.setAttribute('aria-label', 'Play generated ' + option.label + ' audio');
                play.appendChild(el('span', 'a2t-play__icon'));
                play.firstChild.setAttribute('aria-hidden', 'true');
                cell.appendChild(play);
                return;
            }

            cell.appendChild(el('span', 'a2t-tts__state', option.cellState));

            if (option.cellState === 'Queued' || option.cellState === 'Generating') {
                working = true;
            }
        });

        return working;
    }

    /**
     * Watch a row until nothing on it is still being generated.
     *
     * Only while something is actually in flight, and only the one row: a listing that polled every
     * order every few seconds would cost more than the feature is worth. The worker takes minutes on a
     * long call, so the cap is generous and giving up leaves the page correct on its next load.
     */
    function watchTts(row, url) {
        clearTimeout(ttsPoll);

        var attempts = 0;

        function tick() {
            if (++attempts > 120) {
                return;
            }

            load(url).then(function (data) {
                if (paintTtsCell(row, data.options)) {
                    ttsPoll = setTimeout(tick, 4000);
                }
            }).catch(function () {
                // A hiccup is not worth a message: the row is right again on the next page load.
            });
        }

        ttsPoll = setTimeout(tick, 2500);
    }

    function chooseTts(option) {
        // Copied, not derived. `action`, `outputType` and `expectedHash` name the exact job, the exact
        // `output_type` row and the transcript this choice was offered for; working any of them out
        // here would be this script forming an opinion about a table it cannot see.
        ttsForm.action = option.action;
        ttsOutput.value = option.outputType;
        ttsHash.value = option.expectedHash;
        ttsSubmit.disabled = false;
    }

    function openTts(button) {
        openDialog(ttsDialog);
        empty(ttsOptions);
        ttsForm.hidden = true;
        ttsSubmit.disabled = true;
        ttsForm.action = '';
        ttsOutput.value = '';
        ttsHash.value = '';
        // The row this belongs to, and its endpoint: both are read again after a generation, so what
        // the dialog shows on its next open is what the server says then rather than what it said now.
        ttsRow = button.closest('tr');
        ttsUrl = button.getAttribute('data-a2t-tts');
        var order = button.getAttribute('data-a2t-order');
        ttsMeta.textContent = order ? 'Order ' + order : 'No order id';
        say(ttsStatus, 'Loading…');

        load(ttsUrl).then(function (data) {
            if (!data.options.length) {
                say(ttsStatus, 'There is nothing to generate for this order yet.');
                return;
            }
            if (!data.providerConfigured) {
                say(ttsStatus, 'AI audio is not configured on this server.');
            } else {
                quiet(ttsStatus);
            }

            data.options.forEach(function (option, position) {
                var choice = el('label', 'a2t-tts-option');
                if (!option.selectable) {
                    choice.setAttribute('data-disabled', '');
                }
                var input = document.createElement('input');
                input.type = 'radio';
                input.name = 'a2t_choice';
                input.value = String(position);
                input.disabled = !option.selectable;
                input.addEventListener('change', function () { chooseTts(option); });
                choice.appendChild(input);

                var text = el('span', 'a2t-tts-option__body');
                text.appendChild(el('span', 'a2t-tts-option__label', option.label));
                if (typeof option.characters === 'number') {
                    text.appendChild(el(
                        'span',
                        'a2t-tts-option__meta',
                        option.characters + ' characters · ' + option.turns + ' turns'
                    ));
                }
                // A refused choice keeps its reason. "Why can't I generate the caller side" is the
                // question this dialog exists to answer.
                if (option.reason) {
                    text.appendChild(el('span', 'a2t-tts-option__reason', option.reason));
                }
                choice.appendChild(text);
                ttsOptions.appendChild(choice);
            });

            ttsForm.hidden = false;
        }).catch(function (error) {
            fail(ttsStatus, error.message, function () { openTts(button); });
        });
    }

    /**
     * Generate without leaving the listing.
     *
     * The same form, the same fields, the same endpoint — asked for as JSON instead of as a page. An
     * administrator looking at twenty orders pressed a button in one cell; sending them to that one
     * recording's page is losing their place to tell them a sentence.
     */
    if (ttsForm) {
        ttsForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (ttsBusy) {
                return;
            }

            ttsBusy = true;
            var label = ttsSubmit.textContent;
            ttsSubmit.disabled = true;
            ttsSubmit.textContent = 'Queuing…';
            quiet(ttsStatus);

            var row = ttsRow;
            var url = ttsUrl;

            fetch(ttsForm.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                // The form's own fields, so the token, the output type and the hash are exactly the
                // ones the server put there — and the server revalidates every one of them.
                body: new URLSearchParams(new FormData(ttsForm)).toString()
            }).then(function (response) {
                return response.json().then(
                    function (data) { return data; },
                    function () {
                        return { success: false, message: 'The server could not confirm this request.' };
                    }
                );
            }).then(function (data) {
                ttsBusy = false;
                ttsSubmit.disabled = false;
                ttsSubmit.textContent = label;

                if (!data.success) {
                    // Left open on purpose: the choice is still made, and the reason is beside it.
                    say(ttsStatus, data.message);
                    return;
                }

                closeDialog(ttsDialog);
                announce(data.message, data.queued ? 'success' : 'info');

                // The row's own state, read back from the server rather than assumed here.
                if (row !== null && url !== null) {
                    load(url).then(function (fresh) {
                        if (paintTtsCell(row, fresh.options)) {
                            watchTts(row, url);
                        }
                    }).catch(function () {
                        // The cell is right again on the next page load.
                    });
                }
            }).catch(function () {
                ttsBusy = false;
                ttsSubmit.disabled = false;
                ttsSubmit.textContent = label;
                say(ttsStatus, 'Connection interrupted. Nothing was queued — try again.');
            });
        });
    }

    /* ---- Details: the same corrections, in a dialog ---------------------------------------- */

    var reviewBody = document.querySelector('[data-a2t-review-body]');
    var reviewScroll = document.querySelector('[data-a2t-review-scroll]');
    var reviewNoticeBox = document.querySelector('[data-a2t-review-notice]');
    var reviewStatus = document.querySelector('[data-a2t-review-status]');
    var reviewTitle = document.querySelector('[data-a2t-review-title]');
    var reviewMeta = document.querySelector('[data-a2t-review-meta]');
    var reviewToken = document.querySelector('[data-a2t-review-token] input');
    var iconBank = document.querySelector('[data-a2t-iconbank]');
    var historyHost = document.querySelector('[data-a2t-history-host]');
    var fullEditor = document.querySelector('[data-a2t-full-editor]');
    var reviewDialog = dialogOf(reviewBody);

    var reviewUrl = null;
    var reviewTabs = document.querySelector('[data-a2t-review-tabs]');
    /**
     * Payloads already read in THIS open dialog, keyed by their fragment url.
     *
     * Only a tab switch reads from it, and only for the lifetime of the dialog. Every path that
     * changes a recording — a correction, a generation request, the watcher following one — re-reads
     * and overwrites the entry, so nothing here can be older than the last thing that happened.
     */
    var channelCache = {};
    /** The revision trail that goes with each of those, keyed the same way and cleared with them. */
    var historyCache = {};
    var orderLabel = null;     // "Order #123123" while a whole call is open; null for one recording
    var channelLabel = null;   // the channel's own name, for the meta line when tabs are in play
    var version = -1;
    var busy = false;

    // The correction page's own controls, from the module both screens run. Nothing about the
    // selection rule, the inline editor or either confirmation is re-decided here.
    var turns = window.KFReviewTurns;
    var moveDialog = document.querySelector('[data-a2t-move-dialog]');
    var moveForm = moveDialog ? moveDialog.querySelector('[data-a2t-move-form]') : null;
    var mergeDialog = document.querySelector('[data-a2t-merge-dialog]');
    var mergeForm = mergeDialog ? mergeDialog.querySelector('[data-a2t-merge-form]') : null;
    var moveParts = moveForm ? turns.moveParts(moveDialog, moveForm) : null;
    var mergeParts = mergeForm ? turns.mergeParts(mergeDialog, mergeForm) : null;
    var picked = null; // the highlighted range, when it lies inside exactly one turn

    /**
     * One round control carrying one icon, cloned from the bank the template rendered.
     *
     * The SVG is never written out here: it is the same markup the correction page draws, so the two
     * screens cannot end up with two slightly different pencils. Only the label is set per turn,
     * because "Move to Customer" and "Move to Agent" are one icon saying two things.
     */
    function iconButton(name, label) {
        var source = iconBank ? iconBank.querySelector('[data-a2t-icon="' + name + '"]') : null;
        var button = el('button', 'a2t-iconbtn');
        button.type = 'button';
        button.title = label;

        if (source) {
            button.appendChild(source.content.cloneNode(true));
            var hidden = button.querySelector('.a2t-sr');
            if (hidden) {
                hidden.textContent = label;
            }
        } else {
            button.textContent = label;
        }

        return button;
    }

    /**
     * Send one correction to the route that already performs it.
     *
     * The body is exactly what the plain form posts — same field names, same `expected_review_count`,
     * so a stale dialog loses to the service's version guard exactly as a stale tab does. The token
     * comes from a rendered input and travels as `X-CSRF-Token`, the header the existing middleware
     * already accepts; nothing about it is composed here.
     */
    function correct(url, fields) {
        if (busy || reviewToken === null) {
            return;
        }
        busy = true;
        // A correction can remove the turn the highlight was on, so the selection is dropped before
        // the re-read rather than left pointing at a node that will not come back.
        turns.showControlsFor(reviewScroll, null);
        picked = null;
        // Where the reader was. A correction changes one turn in a conversation that may be forty
        // turns long, and throwing them back to the top to read a one-line confirmation is its own
        // small punishment for having corrected something.
        var resumeAt = reviewScroll ? reviewScroll.scrollTop : 0;
        say(reviewStatus, 'Saving…');

        var body = new URLSearchParams();
        body.set('expected_review_count', String(version));
        Object.keys(fields).forEach(function (name) { body.set(name, fields[name]); });


        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': reviewToken.value
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(
                function (data) { return data; },
                function () {
                    return { success: false, message: 'The server could not confirm this correction.' };
                }
            );
        }).then(function (data) {
            busy = false;
            // Re-read either way. A refusal means the conversation moved on under this dialog, and
            // the page's own answer to that is to show what is there now — which is what the redirect
            // after a plain submission does too.
            renderReview(data.message, resumeAt);
        }).catch(function () {
            busy = false;
            renderReview('Connection interrupted. This shows the conversation as the server has it.', resumeAt);
        });
    }

    /**
     * Send a rendered form, rather than fields this script assembled.
     *
     * The move and merge confirmations are the correction page's own forms, filled by the shared
     * module. Taking their `FormData` means the dialog posts precisely what that page posts —
     * including the disabled range inputs a whole-turn merge deliberately leaves out — instead of a
     * second opinion about what the request should contain.
     */
    function submitForm(form) {
        var fields = {};

        // The version and the token are the two fields this dialog owns rather than the form: the
        // version comes from the last read, which is fresher than anything rendered once when the
        // page loaded, and the token travels as a header. Everything else is sent as the form has it.
        new FormData(form).forEach(function (value, name) {
            if (name !== 'expected_review_count' && name !== '_csrf') {
                fields[name] = value;
            }
        });

        correct(form.getAttribute('action') || '', fields);
    }

    /** The confirm / discard strip, in the correction page's own classes and its own words. */
    function reviewNotice(data) {
        empty(reviewNoticeBox);

        var strip = el('div', 'a2t-review__status');

        if (data.voice) {
            // The upload named the speaker, so there is nothing to establish and nothing to confirm.
            strip.appendChild(el(
                'span',
                'a2t-review__state a2t-review__state--confirmed',
                'This recording is the ' + data.voice + ' side of the call, so every message in it '
                    + 'is theirs.'
            ));
            reviewNoticeBox.appendChild(strip);
            return;
        }

        if (data.confirmedLine) {
            strip.appendChild(el('span', 'a2t-review__state a2t-review__state--confirmed', data.confirmedLine));
        } else if (data.rolesPublished) {
            strip.appendChild(el(
                'span',
                'a2t-review__state',
                'The system separated these speakers confidently. Corrections here keep those labels.'
            ));
        } else {
            strip.appendChild(el(
                'span',
                'a2t-review__state a2t-review__state--unconfirmed',
                'The system could not tell which speaker is the agent. Corrections are saved, but the '
                    + 'conversation stays labelled by speaker until you confirm the roles.'
            ));
        }

        if (data.canConfirm) {
            var confirm = el('button', 'btn btn--sm btn--primary', 'Confirm speaker roles');
            confirm.type = 'button';
            confirm.addEventListener('click', function () { correct(data.urls.confirm, {}); });
            strip.appendChild(confirm);
        } else if (data.confirmBlockedReason) {
            var blocked = el('button', 'btn btn--sm', 'Confirm speaker roles');
            blocked.type = 'button';
            blocked.disabled = true;
            strip.appendChild(blocked);
            strip.appendChild(el('span', 'a2t-review__hint', data.confirmBlockedReason));
        }

        if (data.isReviewed) {
            var revert = el('button', 'btn btn--sm btn--danger', 'Discard all corrections');
            revert.type = 'button';
            revert.addEventListener('click', function () {
                if (window.confirm(
                    'Discard all corrections and return to the system’s original result? This is recorded.'
                )) {
                    correct(data.urls.revert, {});
                }
            });
            strip.appendChild(revert);
        }

        reviewNoticeBox.appendChild(strip);
    }

    /**
     * One turn, with the two controls the correction page puts beside a bubble and nothing else.
     *
     * The markup is that page's markup — same classes, same `data-a2t-*` hooks, same wording — so
     * `KFReviewTurns` drives it here exactly as it drives the page. The Split control is deliberately
     * absent: the page offers it only inside its `<noscript>` fallback, so a scripting browser has
     * never seen one and this must not invent it.
     */
    function reviewTurn(turn) {
        var drawn = bubble(turn);
        var row = drawn.row;

        // Everything the shared module reads off a turn. It asks the DOM rather than a payload,
        // because the page it was written for has no payload — so the dialog answers in the same way.
        row.setAttribute('data-a2t-turn', String(turn.index));
        row.setAttribute('data-a2t-label', turn.label);
        if (turn.canMove) {
            row.setAttribute('data-a2t-target-role', turn.targetRole);
            row.setAttribute('data-a2t-target-label', turn.targetLabel);
            row.setAttribute('data-a2t-merges', turn.moveMerges ? '1' : '0');
            row.setAttribute('data-a2t-move-url', turn.urls.moveText);
        }
        row.setAttribute('data-a2t-merge-url', turn.urls.merge);

        // The rendered wording carries the stored one alongside it, so Cancel restores what was
        // typed rather than the normalised reading of it.
        drawn.text.setAttribute('data-a2t-text', '');
        drawn.text.setAttribute('data-a2t-raw', turn.text);

        var tools = el('span', 'a2t-turn__tools');
        tools.setAttribute('data-a2t-tools', '');

        // The handle first, the pencil behind it — the order and the side the page uses. Absent where
        // the server says there is nowhere to move to: a recording whose speaker was named at upload
        // time has no other speaker, and `canMove` is that answer rather than this script's guess.
        if (turn.canMove) {
            var move = iconButton('move', 'Move this message to the ' + turn.targetLabel);
            move.setAttribute('data-a2t-move', '');
            tools.appendChild(move);
        }

        var edit = iconButton('edit', 'Correct the wording');
        edit.setAttribute('data-a2t-edit', '');
        tools.appendChild(edit);

        // Only where there is something to show. `hasHistory` is TurnLineage's answer, read from the
        // audit trail — a revert clears a message's history and an edit gives it one, neither of
        // which "looks edited" would get right.
        if (turn.hasHistory) {
            var history = iconButton('history', 'Show what was corrected');
            history.setAttribute('data-a2t-history', String(turn.index));
            tools.appendChild(history);
        }

        drawn.body.appendChild(tools);
        row.appendChild(editorFor(turn));
        row.appendChild(mergeControlsFor(turn));

        return row;
    }

    /** The inline wording editor: the same form the correction page renders under each bubble. */
    function editorFor(turn) {
        var editor = document.createElement('form');
        editor.className = 'a2t-turn__editor';
        editor.setAttribute('data-a2t-editor', '');
        editor.method = 'post';
        editor.action = turn.urls.text;
        editor.hidden = true;

        var area = document.createElement('textarea');
        area.className = 'field__control a2t-turn__textarea';
        area.name = 'text';
        area.rows = 3;
        area.setAttribute('data-a2t-editor-text', '');
        area.setAttribute('aria-label', 'Corrected wording');
        // Seeded with the stored wording, never the displayed one.
        area.value = turn.text;

        var cancel = el('button', 'btn btn--sm', 'Cancel');
        cancel.type = 'button';
        cancel.setAttribute('data-a2t-edit-cancel', '');

        var save = el('button', 'btn btn--sm btn--primary', 'Save');
        save.type = 'submit';
        save.setAttribute('data-a2t-edit-save', '');

        var actions = el('div', 'a2t-turn__editor-actions');
        actions.appendChild(cancel);
        actions.appendChild(save);

        editor.appendChild(area);
        editor.appendChild(actions);

        return editor;
    }

    /**
     * The merge strip, hidden until this turn's own words are highlighted.
     *
     * A direction is offered when there is a neighbour on that side and withheld when there is not —
     * the page's rule exactly, and the reason it is the server's `available` rather than a count of
     * DOM siblings.
     */
    function mergeControlsFor(turn) {
        var controls = el('div', 'a2t-turn__merge');
        controls.setAttribute('data-a2t-merge-controls', '');
        controls.hidden = true;

        var row = el('div', 'a2t-turn__merge-row');
        row.appendChild(el('span', 'a2t-turn__merge-label', 'Merge this message:'));

        [
            { direction: 'previous', merge: turn.mergePrevious, label: 'With previous' },
            { direction: 'next', merge: turn.mergeNext, label: 'With next' }
        ].forEach(function (option) {
            if (!option.merge.available) {
                return;
            }
            var chip = el('button', 'a2t-mergebtn', option.label);
            chip.type = 'button';
            chip.setAttribute('data-a2t-merge-with', option.direction);
            row.appendChild(chip);
        });

        controls.appendChild(row);

        return controls;
    }

    /* ---- Manage Audio: what an order holds, and replacing one of it ------------------------- */

    var manageBody = document.querySelector('[data-a2t-manage-body]');
    var manageSlots = document.querySelector('[data-a2t-manage-slots]');
    var manageStatus = document.querySelector('[data-a2t-manage-status]');
    var manageMeta = document.querySelector('[data-a2t-manage-meta]');
    var manageToken = document.querySelector('[data-a2t-manage-token] input');
    var manageDialog = dialogOf(manageBody);
    var manageUrl = null;
    var manageBusy = false;
    var manageTitle = document.querySelector('[data-a2t-manage-title]');
    /**
     * One-shot: open the upload form of the channel that was clicked, then forget it.
     *
     * Consumed by `revealRequestedSlot` so a re-read after an upload does not spring the form open
     * again underneath the confirmation it is showing.
     */
    var manageFocus = null;
    /**
     * The MODE, which lasts as long as the dialog is open: one channel, or all of them.
     *
     * Deliberately separate from `manageFocus`. That one is consumed on first paint; this must not be,
     * or the re-read after a successful upload would suddenly reveal the two channels the operator did
     * not ask about. Reset on every open, so a Manage Audio press after an "+ Add audio" press shows
     * everything and nothing leaks between openings.
     */
    var manageOnly = null;

    function openManage(button) {
        if (!manageDialog) {
            return;
        }
        openDialog(manageDialog);
        empty(manageSlots);
        manageBody.hidden = true;
        manageUrl = button.getAttribute('data-a2t-manage');
        // Set only by the "+ Add audio" button in an empty table cell, which names the column it stands
        // for. Pressed from there, the dialog shows THAT channel and nothing else: the operator asked to
        // add one recording, and offering the other two is an invitation to upload against the wrong
        // column. `Manage Audio` carries no such attribute and still lists all three.
        //
        // Read once into both: the mode lasts while the dialog is open, the reveal is spent on the
        // first paint. Assigned on every open, so neither survives into the next one.
        manageOnly = button.getAttribute('data-a2t-manage-focus');
        manageFocus = manageOnly;
        var order = button.getAttribute('data-a2t-order');
        manageMeta.textContent = order ? 'Order ' + order : 'No order id';

        if (manageTitle) {
            // Named later from the payload, which is where the channel's own label lives — the button
            // carries the stored value, and turning MIXED into "Mix / Common" here would be a second
            // copy of a mapping the server already sends.
            manageTitle.textContent = manageOnly === null ? 'Manage Audio' : 'Add audio';
        }
        say(manageStatus, 'Loading…');

        loadManage();
    }

    /**
     * Read, or re-read: after an upload the dialog asks again rather than guessing what changed.
     *
     * `keep` is the confirmation of whatever caused the re-read. Without it the reload would clear the
     * message it was triggered by, and an administrator who had just uploaded a replacement would be
     * shown a refreshed list and no word about whether it worked.
     */
    function loadManage(keep) {
        var requested = manageUrl;

        return load(requested).then(function (data) {
            if (manageUrl !== requested) {
                return; // The dialog moved on to another order while this was in flight.
            }
            paintManage(data);
            if (keep) {
                say(manageStatus, keep);
            } else {
                quiet(manageStatus);
            }
            manageBody.hidden = false;
        }).catch(function (error) {
            if (manageUrl === requested) {
                fail(manageStatus, error.message, function () { loadManage(keep); });
            }
        });
    }

    function paintManage(data) {
        empty(manageSlots);

        // Opened from one column: that channel alone. Filtered on the SERVER'S value for each slot
        // rather than on anything the browser worked out, and an unknown mode matches nothing and is
        // shown as the whole dialog — the same answer as not asking for a channel at all.
        var slots = manageOnly === null
            ? data.slots
            : data.slots.filter(function (slot) { return slot.recordingType === manageOnly; });

        if (slots.length === 0) {
            slots = data.slots;
        }

        // Why nothing here can be replaced, said once at the top rather than three times over.
        if (!data.canReplace && data.reason) {
            manageSlots.appendChild(el('p', 'a2t-manage__note', data.reason));
        }

        slots.forEach(function (slot) {
            manageSlots.appendChild(manageSlot(slot, data));
        });

        if (manageTitle && manageOnly !== null && slots.length === 1) {
            // The channel's own name, as the server spells it.
            manageTitle.textContent = 'Add ' + slots[0].label + ' audio';
        }

        revealRequestedSlot();
    }

    /**
     * Open the upload form for the type the administrator actually clicked, if they came from a cell.
     *
     * Consumed once: a later reload of this dialog — after an upload, say — must not spring the form
     * open again underneath the confirmation it was showing.
     */
    function revealRequestedSlot() {
        if (!manageFocus) {
            return;
        }

        var wanted = manageFocus;
        manageFocus = null;

        var button = manageSlots.querySelector('[data-a2t-replace="' + wanted + '"]');
        var slot = button ? button.closest('.a2t-manage__slot') : null;
        var form = slot ? slot.querySelector('[data-a2t-replace-form]') : null;

        if (!form) {
            return;
        }

        form.hidden = false;

        if (typeof slot.scrollIntoView === 'function') {
            slot.scrollIntoView({ block: 'nearest' });
        }
    }

    function manageSlot(slot, data) {
        var section = el('section', 'a2t-manage__slot');
        var head = el('div', 'a2t-manage__head');
        head.appendChild(el('h3', 'a2t-manage__title', slot.label));

        if (slot.canReplace) {
            // Words, and the right words: replacing an existing recording and adding a missing one are
            // different actions to the person doing them, though the server treats them alike.
            var button = el('button', 'btn btn--sm btn--secondary',
                slot.versions.length ? 'Replace' : 'Upload');
            button.type = 'button';
            button.setAttribute('data-a2t-replace', slot.recordingType);
            head.appendChild(button);
        }

        section.appendChild(head);

        if (!slot.versions.length) {
            section.appendChild(el('p', 'a2t-manage__note', 'Not uploaded.'));
        }

        slot.versions.forEach(function (version) {
            // The number is the server's: it counts uploads, which is not the same as counting rows in
            // this list once a replacement that has not finished sits above the current recording.
            section.appendChild(manageVersion(version, version.version));
        });

        section.appendChild(replaceForm(slot, data));

        return section;
    }

    /**
     * One version: what it is, and every way of opening it.
     *
     * Numbered from the bottom, so v1 is the first recording ever uploaded for this slot and the number
     * never changes when another replacement arrives above it.
     */
    function manageVersion(version, number) {
        var row = el('div', 'a2t-manage__version' + (version.current ? ' a2t-manage__version--current' : ''));

        var head = el('div', 'a2t-manage__vhead');
        head.appendChild(el('span', 'a2t-manage__vnum', 'v' + number));
        head.appendChild(el('span', 'a2t-manage__vstate',
            version.current ? 'Current' : (version.settled ? 'Superseded' : version.statusLabel)));
        // A replacement that has not finished, or one that failed, says so in its own words rather
        // than being described as history it is not yet.
        if (!version.current && !version.settled) {
            head.appendChild(el('span', 'a2t-manage__vwarn', 'Replacement in progress'));
        } else if (!version.current && version.status === 'FAILED') {
            head.appendChild(el('span', 'a2t-manage__vwarn', 'Failed'));
        }
        row.appendChild(head);

        var meta = [version.filename, version.providerLabel, stamp(version.uploadedAt)];
        var length = seconds(version.durationSeconds);
        if (length) {
            meta.push(length);
        }
        row.appendChild(el('p', 'a2t-manage__vmeta', meta.filter(Boolean).join(' · ')));

        var links = el('div', 'a2t-manage__vlinks');
        [
            ['originalUrl', 'Original audio'],
            ['transcriptUrl', 'Transcript'],
            ['reviewUrl', 'Corrections'],
            ['aiAudioUrl', 'AI audio']
        ].forEach(function (pair) {
            if (!version[pair[0]]) {
                return;
            }
            var link = el('a', 'a2t-slot__link', pair[1]);
            link.href = version[pair[0]];
            links.appendChild(link);
        });

        if (links.childNodes.length) {
            row.appendChild(links);
        }

        return row;
    }

    /**
     * The upload, hidden until Replace is pressed.
     *
     * Rendered per slot rather than shared, so the recording type is a fixed field of the form the
     * reader is looking at rather than something a shared form has to be re-pointed at.
     */
    function replaceForm(slot, data) {
        var form = document.createElement('form');
        form.className = 'a2t-manage__form';
        form.method = 'post';
        form.action = data.action;
        form.enctype = 'multipart/form-data';
        form.hidden = true;
        form.setAttribute('data-a2t-replace-form', slot.recordingType || '');

        form.appendChild(el('p', 'a2t-manage__note',
            'A new version is created and transcribed on its own. The current recording stays in use '
            + 'until the replacement finishes, and stays current if it fails. Earlier transcripts, '
            + 'corrections and generated audio stay with the version they belong to, and none of them '
            + 'is carried over — including whether AI audio was paid for, which is asked below.'));

        var type = document.createElement('input');
        type.type = 'hidden';
        type.name = 'recording_type';
        type.value = slot.recordingType || '';
        form.appendChild(type);

        form.appendChild(labelledField(
            'Audio file',
            (function () {
                var file = document.createElement('input');
                file.type = 'file';
                file.className = 'field__control a2t-manage__file';
                file.name = 'audio';
                file.required = true;
                file.setAttribute('aria-label', 'Replacement audio file for ' + slot.label);
                return file;
            }())
        ));

        // The same two choices the store page's own upload form offers, answered by the same server
        // object — the labels, the availability and which one starts selected all arrive as data.
        form.appendChild(labelledField('Transcription provider', providerSelect(slot, data)));
        form.appendChild(aiAudioField(data));

        var actions = el('div', 'a2t-manage__actions');
        var submit = el('button', 'btn btn--sm btn--primary', 'Upload replacement');
        submit.type = 'submit';
        actions.appendChild(submit);
        var cancel = el('button', 'btn btn--sm', 'Cancel');
        cancel.type = 'button';
        cancel.setAttribute('data-a2t-replace-cancel', '');
        actions.appendChild(cancel);
        form.appendChild(actions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitReplacement(form, submit);
        });

        return form;
    }

    /** A field of the page's own shape: a label above its control. */
    function labelledField(text, control) {
        var field = el('div', 'field a2t-manage__field');
        var label = el('label', 'field__label', text);
        if (!control.id) {
            control.id = 'a2t-mf-' + (++fieldSeq);
        }
        label.htmlFor = control.id;
        field.appendChild(label);
        field.appendChild(control);
        return field;
    }

    var fieldSeq = 0;

    /**
     * Every provider, with the one this recording was transcribed with already chosen.
     *
     * A provider this machine cannot run is listed and marked rather than hidden — an administrator
     * who cannot see the choice cannot tell a single-provider install from a broken one — and is
     * `disabled`, which is a courtesy: the server refuses it whatever is posted.
     */
    function providerSelect(slot, data) {
        var select = document.createElement('select');
        select.className = 'field__control';
        select.name = 'transcription_provider';

        (data.providers || []).forEach(function (provider) {
            var option = document.createElement('option');
            option.value = provider.value;
            option.textContent = provider.label + (provider.usable ? '' : ' — Not configured');
            option.disabled = !provider.usable;
            // The server named the one to start on, from the recording being replaced.
            option.selected = provider.value === slot.provider;
            select.appendChild(option);
        });

        return select;
    }

    /**
     * The paid opt-in, unticked every time this form is built.
     *
     * Never carried over from the recording being replaced: the flag is what makes the worker buy
     * audio unasked, and replacing a mistaken upload is not a request to pay for it again. Rebuilt
     * rather than reset, so reopening the form cannot show a box somebody ticked and cancelled.
     */
    function aiAudioField(data) {
        var field = el('div', 'field a2t-manage__field');
        var label = el('label', 'a2t-checkbox');
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.name = 'generate_ai_audio';
        box.value = '1';
        box.checked = false;
        box.disabled = !data.aiAudioConfigured;
        label.appendChild(box);
        label.appendChild(el('span', null, 'Generate clean AI audio after transcription'));
        field.appendChild(label);
        field.appendChild(el('div', 'field__hint', data.aiAudioConfigured
            ? 'Costs money. Off by default, and never carried over from the recording being replaced.'
            : 'Not configured on this server yet, so nothing would be generated.'));

        return field;
    }

    function submitReplacement(form, submit) {
        if (manageBusy || !manageToken) {
            return;
        }
        // One at a time. A second press while the first upload is in flight would put two recordings
        // of the same side on the order, and the reader has no reason to think they did that.
        manageBusy = true;
        submit.disabled = true;
        var label = submit.textContent;
        submit.textContent = 'Uploading…';

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': manageToken.value
            },
            body: new FormData(form)
        }).then(function (response) {
            return response.json().then(
                function (data) { return data; },
                function () {
                    return { success: false, message: 'The server could not confirm this upload.' };
                }
            );
        }).then(function (data) {
            manageBusy = false;
            submit.disabled = false;
            submit.textContent = label;
            say(manageStatus, data.message);

            // Re-read on success: the new version appears because the server says it is there, and a
            // refusal leaves the dialog exactly as it was so the file can be chosen again. The
            // message is carried through the re-read, which would otherwise clear it.
            if (data.success) {
                loadManage(data.message);
            }
        }).catch(function () {
            manageBusy = false;
            submit.disabled = false;
            submit.textContent = label;
            say(manageStatus, 'Connection interrupted. Nothing was uploaded — try again.');
        });
    }

    /** An ISO stamp as the page's other dates read. */
    function stamp(iso) {
        var when = new Date(iso);
        return isFinite(when.getTime())
            ? when.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
            : '';
    }

    /* ---- Listening to one recording -------------------------------------------------------- */

    var listen = document.querySelector('[data-a2t-listen]');
    var reviewActions = document.querySelector('[data-a2t-review-actions]');
    var listenBusy = false;
    var lastAudio = null;      // the audio block from the last read, for the Generate button
    var lastFragment = null;   // the whole of it, for the two dialogs that open on top of this one
    var generatedTimer = null; // set only while a generation is in flight; see watchGenerated()

    /**
     * One group of the toolbar: a caption and its controls, side by side on one line.
     *
     * A group rather than a row, because the three of them sit beside each other on a wide screen and
     * only stack when there is genuinely no room. The caption is inside the group rather than in a
     * column of its own, so a stacked layout does not leave a ragged gutter where the widest label was.
     */
    function listenGroup(label, controls, modifier) {
        var group = el('div', 'a2t-listen__group' + (modifier ? ' a2t-listen__group--' + modifier : ''));
        group.appendChild(el('span', 'a2t-listen__label', label));
        var row = el('div', 'a2t-listen__row');
        controls.forEach(function (node) { row.appendChild(node); });
        group.appendChild(row);
        listen.appendChild(group);
    }

    function player(url) {
        var audio = document.createElement('audio');
        audio.controls = true;
        // Metadata only: a dialog may be opened on a recording nobody presses play on, and fetching
        // several megabytes to find that out would be paid for in somebody's bandwidth.
        audio.preload = 'metadata';
        audio.src = url;
        return audio;
    }

    /**
     * The three ways to hear this recording, drawn from what the server decided.
     *
     * Nothing here works out whether a rendition is stale, whether a paid button may be shown, or
     * what to call a state: those are `AiAudioPage`'s answers, arriving as data. This places them.
     */
    function paintListen(data) {
        if (!listen) {
            return;
        }

        empty(listen);
        var audio = data.audio || {};
        lastAudio = audio;
        lastFragment = data;

        // 1. The file somebody uploaded. Served by the existing route, which resolves the stored
        //    name through the one path builder for retained recordings.
        if (audio.original && audio.original.available) {
            listenGroup('Original', [player(audio.original.url)]);
        } else {
            listenGroup('Original', [
                el('span', 'a2t-listen__note', 'Unavailable.'),
            ]);
        }

        // 2. The audio this application generated from the transcript.
        listenGroup('AI audio', generatedControls(audio.generated));

        // 3. The browser reading the transcript aloud. Costs nothing and leaves nothing behind.
        listenGroup('System', speechControls(), 'speech');

        // 4. What can be DONE to this recording, as opposed to heard. In the dialog's header, not here:
        //    these two reach a provider or replace a file, and the three above only play things.
        paintRecordingActions(data);

        // 5. Keep asking, but only while there is an answer coming. Nothing pushes the end of a
        //    generation to an open page, so a dialog left open would otherwise sit on "Queued…" until
        //    somebody reloaded — and the operator who pressed the button is exactly the person watching.
        watchGenerated(audio.generated);

        listen.hidden = false;
    }

    /**
     * Re-read this recording while a worker is generating its audio, and stop as soon as it is not.
     *
     * Reuses the dialog's own endpoint rather than adding one, and repaints ONLY the audio panel and the
     * header actions — never the transcript. That distinction is the point: `renderReview` rebuilds
     * every turn, and doing that on a timer would throw away an editor somebody had open, lose a
     * selection mid-merge and jump the scroll, all to report a state change in a strip at the top.
     *
     * Stopped by its own state (the server stops saying `inFlight`), by the dialog closing, and by the
     * dialog moving to another recording — the last of which is why `requested` is compared.
     */
    function watchGenerated(generated) {
        stopWatchingGenerated();

        if (!generated || !generated.inFlight || reviewUrl === null) {
            return;
        }

        var requested = reviewUrl;

        generatedTimer = setTimeout(function () {
            generatedTimer = null;

            load(requested).then(function (fresh) {
                // Moved on, or closed, while that was in flight. The worker carries on either way.
                if (reviewUrl !== requested || !reviewDialog.open) {
                    return;
                }

                // The cached copy too, or switching away and back would show the state this read just
                // replaced. The panel is repainted; the transcript is deliberately left alone.
                channelCache[requested] = fresh;
                paintListen(fresh);
            }).catch(function () {
                // A lost poll is not a lost generation. Ask again; the panel says nothing new meanwhile.
                if (reviewUrl === requested && reviewDialog.open) {
                    watchGenerated(generated);
                }
            });
        }, 3000);
    }

    function stopWatchingGenerated() {
        if (generatedTimer !== null) {
            clearTimeout(generatedTimer);
            generatedTimer = null;
        }
    }

    /** Run something against the header's generate control, when there is one drawn. */
    function headerAction(change) {
        var control = reviewActions && reviewActions.querySelector('[data-a2t-tts-confirm]');

        if (control) {
            change(control);
        }
    }

    /**
     * "Update Audio" and "Generate / Regenerate AI Audio", in the dialog's own header.
     *
     * Both were previously only reachable from the page behind the dialog — the row's Manage Audio
     * button, or the AI audio page. An administrator reading a transcript and deciding it is wrong had
     * to close the dialog to act on that, and then find the row again.
     *
     * Everything here is the server's answer, not a decision taken in the browser: whether the recording
     * can be replaced at all (`data.replace`), whether the generate control is drawn (`offered`), whether
     * it may be pressed (`canGenerate`), what it says (`actionLabel`) and whether pressing it would cost
     * anything (`paid`). This places them.
     *
     * ## The generate control is not removed while a worker has it
     *
     * It used to be, because it was drawn on `canGenerate` alone — and that is false while a generation
     * is in flight. Pressing it therefore made it vanish: the request succeeded, the panel re-read, the
     * state was Queued, and the only evidence that anything had happened was that the button had gone.
     * It now stays where it was, disabled, saying "Queued…" and then "Generating…", and comes back by
     * itself. `offered` says whether to draw it; `canGenerate` still says whether it may be pressed.
     */
    function paintRecordingActions(data) {
        if (!reviewActions) {
            return;
        }

        empty(reviewActions);

        if (data.replace) {
            // Left enabled during a generation, deliberately. A replacement is an additive upload that
            // writes a NEW conversation and a new job, and renditions are keyed by job id — so the
            // generation under way stays bound to the recording it was asked for and finishes there.
            // Disabling this would refuse a safe action to prevent something that cannot happen.
            var update = el('button', 'btn btn--sm', 'Update Audio');
            update.type = 'button';
            update.setAttribute('data-a2t-update-open', '');
            reviewActions.appendChild(update);
        }

        var generated = data.audio && data.audio.generated;

        if (generated && generated.offered) {
            // `actionLabel` is the whole sentence for the state the server found this in — "Generate AI
            // Audio", "Regenerate AI Audio", "Queued…", "Generating…". Nothing is assembled here.
            var tts = el('button', 'btn btn--sm btn--primary', generated.actionLabel);
            tts.type = 'button';
            tts.disabled = !generated.canGenerate;
            tts.setAttribute('data-a2t-tts-confirm', '');
            reviewActions.appendChild(tts);
        }
    }

    /**
     * @return {Array} the controls for whatever state the generated audio is in
     */
    function generatedControls(generated) {
        if (!generated) {
            return [el('span', 'a2t-listen__note', 'This recording cannot produce AI audio.')];
        }

        var controls = [];

        // Playable first, and playable in every state that has a file: stale audio and audio made
        // with an older voice are both still audio, and taking them away would be the one thing an
        // administrator cannot undo without paying again.
        if (generated.playable && generated.playUrl) {
            controls.push(player(generated.playUrl));
        }

        // The state in the server's own words — "Stale", "Queued", "Generating", "Failed". Skipped
        // for a recording that simply has no audio yet: the button beside it already says "Generate",
        // and "Not generated / Generate AI audio" is the same sentence twice in a toolbar built to
        // save room. Every other state says something the button does not.
        if ((!generated.playable || generated.state !== 'Ready') && generated.state !== 'NotGenerated') {
            controls.push(el('span', 'a2t-listen__state', generated.label));
        }

        // The button that asks for generation is NOT here. It lives in the actions row below the
        // players, with Update Audio, because both of those reach outside this page — one spends money,
        // the other replaces a file — and the three groups above only play what already exists. Two
        // buttons doing the same thing in one dialog is the alternative, and it was the first thing
        // written here.
        // "A generation is already under way." is skipped: the header control says "Queued…" or
        // "Generating…" in the same breath, and a dialog that reports one state twice in two places
        // invites the reader to look for the difference between them. Every other reason — no provider,
        // nothing said on this side — has no control saying it, so it is shown.
        if (!generated.canGenerate && !generated.inFlight && generated.reason) {
            controls.push(el('span', 'a2t-listen__note', generated.reason));
        }

        return controls;
    }

    /* ---- Updating this recording's audio ---------------------------------------------------- */

    var updateForm = document.querySelector('[data-a2t-update-form]');
    var updateDialog = dialogOf(updateForm);
    var updateStatus = document.querySelector('[data-a2t-update-status]');
    var updateSubmit = document.querySelector('[data-a2t-update-submit]');
    var updateBusy = false;
    var updateTimer = null;

    /**
     * One line of text inside one dialog, by the attribute the template marked it with.
     *
     * Scoped to the dialog rather than to the document: two of these dialogs are open at once — Update
     * or the confirmation, on top of Details — and an attribute that is unique today is only unique
     * until somebody reuses the name in the other one.
     *
     * An em dash for an absent value, because a blank definition beside its term reads as a bug.
     */
    function fillIn(root, selector, value) {
        var node = root.querySelector(selector);

        if (node) {
            node.textContent = (value === null || value === undefined || value === '') ? '—' : value;
        }
    }

    /**
     * Open Update for the recording the Details dialog is currently showing.
     *
     * Every field comes from `data.replace`, which the server built: the endpoint, this recording's own
     * job public id, its type and its order. Nothing here works out which slot is being replaced — which
     * is the whole reason the type is a fact in a list rather than a select. The server derives it from
     * `replaces` and ignores any type the body carries.
     */
    function openUpdate() {
        var target = lastFragment && lastFragment.replace;

        if (!updateDialog || !target) {
            return;
        }

        // Before `replaces` is written, not after: reset restores every control to the value the markup
        // declared, and for that hidden field the declared value is empty.
        updateForm.reset();
        updateForm.action = target.url;
        updateForm.querySelector('[data-a2t-update-replaces]').value = target.replaces;

        fillIn(updateDialog, '[data-a2t-update-order]', target.orderId ? 'Order ' + target.orderId : null);
        fillIn(updateDialog, '[data-a2t-update-type]', target.recordingTypeLabel);
        fillIn(updateDialog, '[data-a2t-update-current]', lastFragment.filename);
        fillIn(
            updateDialog,
            '[data-a2t-update-meta]',
            target.recordingTypeLabel + ' · order ' + target.orderId,
        );

        quiet(updateStatus);
        // A previous replacement may still be being watched. Its worker carries on either way; this
        // dialog is now about a different recording and must not report the old one's stages.
        stopWatching();
        releaseUpdate();

        openDialog(updateDialog);
    }

    /**
     * Send the replacement, then follow the recording it created.
     *
     * The upload is one request and the progress is another endpoint entirely — the existing job status
     * one, polled on the **new** job. A replacement is a new recording with a new public id, so watching
     * the id this dialog was opened on would report the recording being replaced, which finished long ago.
     */
    function submitUpdate(event) {
        event.preventDefault();

        if (updateBusy) {
            return; // One at a time: a second press would put two recordings on the same slot.
        }

        var file = updateForm.querySelector('input[type="file"]');

        // The input is `required`, so a browser enforcing that never reaches this. Kept for one that
        // does not, because the alternative is a POST with no file and a refusal from the server.
        if (!file || !file.files || !file.files.length) {
            say(updateStatus, 'Choose an audio file to upload.');
            return;
        }

        updateBusy = true;
        updateSubmit.disabled = true;
        updateSubmit.textContent = 'Uploading…';
        say(updateStatus, 'Uploading…');

        fetch(updateForm.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            // Multipart, carrying the form's own CSRF field. Not JSON: this is a file.
            body: new FormData(updateForm)
        }).then(function (response) {
            return response.json().then(
                function (data) { return data; },
                function () {
                    return { success: false, message: 'The server could not confirm this upload.' };
                }
            );
        }).then(function (data) {
            if (!data.success) {
                // A refusal leaves the dialog exactly as it was, so the file can be chosen again.
                releaseUpdate();
                say(updateStatus, data.message || 'The upload was refused.');
                return;
            }

            if (!data.statusUrl) {
                // Queued, but there is nothing to watch. Say what happened rather than inventing progress.
                releaseUpdate();
                say(updateStatus, data.message);
                return;
            }

            say(updateStatus, data.message);
            watchReplacement(data.statusUrl, data.jobPublicId);
        }).catch(function () {
            releaseUpdate();
            say(updateStatus, 'Connection interrupted. Nothing was uploaded — try again.');
        });
    }

    function releaseUpdate() {
        updateBusy = false;
        updateSubmit.disabled = false;
        updateSubmit.textContent = 'Update Audio';
    }

    function stopWatching() {
        if (updateTimer !== null) {
            clearTimeout(updateTimer);
            updateTimer = null;
        }
    }

    /**
     * Follow the new recording through the stages the worker actually reports.
     *
     * No percentage anywhere. The status endpoint publishes a status and a stage and knows nothing about
     * how far through either it is, so a bar would be a number this application invented — and one that
     * sticks at 90% is worse than a sentence saying what is happening.
     */
    function watchReplacement(statusUrl, jobPublicId, misses) {
        stopWatching();
        var failures = misses || 0;

        load(statusUrl).then(function (state) {
            if (!updateDialog.open) {
                return; // Closed while that was in flight. The worker carries on regardless.
            }

            if (state.status === 'COMPLETED') {
                say(updateStatus, 'Finished. Reloading to show the new recording…');
                arriveAt(jobPublicId);
                return;
            }

            if (state.status === 'FAILED') {
                // The real end state, in the page's own words. No reason is published by that endpoint —
                // the failure detail lives on the recording's own page, where the log put it.
                releaseUpdate();
                say(updateStatus, 'The new recording failed. Open it from the table to see why.');
                return;
            }

            say(updateStatus, stageWords(state));
            updateTimer = setTimeout(function () { watchReplacement(statusUrl, jobPublicId, 0); }, 2000);
        }).catch(function () {
            if (!updateDialog.open) {
                return;
            }

            // A lost poll is not a lost upload, so a few are simply retried and the dialog says nothing
            // new. Bounded, because the endpoint also answers 404 for a job that is not there — an
            // unbounded loop would ask for a recording that will never exist every five seconds for as
            // long as the tab stayed open, and say "Uploading…" the whole time.
            if (failures >= 4) {
                releaseUpdate();
                say(
                    updateStatus,
                    'The upload was accepted, but its progress cannot be read just now. '
                    + 'Reload the page to see where it got to.',
                );

                return;
            }

            updateTimer = setTimeout(function () {
                watchReplacement(statusUrl, jobPublicId, failures + 1);
            }, 5000);
        });
    }

    /**
     * Reload onto the recording that now exists.
     *
     * The row behind the dialog is server-rendered and there is no endpoint that re-renders one, so the
     * only truthful way to refresh it is to ask for the page again. The fragment names the recording to
     * open once it arrives, which is what makes this a continuation rather than losing the reader's place.
     */
    function arriveAt(jobPublicId) {
        if (jobPublicId) {
            window.location.hash = 'a2t-recording=' + jobPublicId;
        }
        window.location.reload();
    }

    /** `ProcessingStage` in the same words the page's own upload form uses for it. */
    function stageWords(state) {
        var labels = window.KFAudioStages || {};

        return labels[state.stage] || labels[state.status] || 'Working…';
    }

    if (updateForm) {
        updateForm.addEventListener('submit', submitUpdate);
        updateDialog.addEventListener('close', stopWatching);
    }

    /* ---- Generating this recording's AI audio ----------------------------------------------- */

    var ttsConfirmDialog = document.getElementById('a2t-tts-confirm-dialog');
    var ttsConfirmSubmit = document.querySelector('[data-a2t-tts-confirm-submit]');
    var ttsConfirmStatus = document.querySelector('[data-a2t-tts-confirm-status]');

    /**
     * Ask before spending anything — and say plainly when there is nothing to spend.
     *
     * The button that opens this is shown even for audio that is already current, because that state is
     * the one worth explaining: the dialog says the audio matches the latest transcript and disables its
     * own confirm. Hiding the button instead would leave a reader who came to regenerate wondering
     * whether they had missed it, or whether the feature was broken.
     */
    function openTtsConfirm() {
        var generated = lastAudio && lastAudio.generated;

        if (!ttsConfirmDialog || !generated) {
            return;
        }

        var current = generated.alreadyCurrent === true;
        var replace = lastFragment && lastFragment.replace;

        fillIn(ttsConfirmDialog, '[data-a2t-tts-confirm-title]', generated.buttonLabel + ' AI Audio');
        fillIn(
            ttsConfirmDialog,
            '[data-a2t-tts-confirm-recording]',
            replace ? replace.recordingTypeLabel : generated.outputType,
        );
        fillIn(ttsConfirmDialog, '[data-a2t-tts-confirm-source]', generated.transcriptSource);
        fillIn(ttsConfirmDialog, '[data-a2t-tts-confirm-state]', generated.label);
        fillIn(ttsConfirmDialog, '[data-a2t-tts-confirm-note]', current
            ? 'This AI audio is already current and matches the latest transcript. Nothing would be generated.'
            : 'This reads the latest effective transcript aloud with a paid provider and replaces the '
              + 'recording\'s AI audio when it finishes.');

        quiet(ttsConfirmStatus);
        ttsConfirmSubmit.disabled = current;
        ttsConfirmSubmit.textContent = current ? 'Already current' : generated.buttonLabel;

        openDialog(ttsConfirmDialog);
    }

    if (ttsConfirmSubmit) {
        ttsConfirmSubmit.addEventListener('click', function () {
            // Belt and braces. The button is disabled for audio that is already current, and
            // `TtsGenerationService::enqueue()` answers AlreadyCurrent for a matching digest whatever
            // reaches it — neither of those is the only guard, and neither is this.
            if (ttsConfirmSubmit.disabled) {
                return;
            }

            requestGeneration(ttsConfirmSubmit, ttsConfirmStatus, function () {
                closeDialog(ttsConfirmDialog);
            });
        });
    }

    /**
     * Ask for this recording's AI audio from inside the dialog.
     *
     * The same endpoint, the same fields and the same JSON answer the Generate dialog uses — the
     * server names the output type and the digest, and revalidates both. Nothing about eligibility,
     * cost protection or duplicate suppression is decided here; pressing this reaches
     * `TtsGenerationService::enqueue()` exactly as every other trigger does.
     *
     * `expected_hash` is sent unchanged and is what makes a stale tab harmless: it is the digest of the
     * transcript the dialog was rendered from, and the server refuses a request whose digest no longer
     * matches rather than reading aloud a transcript nobody looked at.
     *
     * @param {Element}  button the control to disable while this is in flight
     * @param {Element}  status where to put the server's answer
     * @param {Function} done   run once the answer is in, whether or not it was a refusal
     */
    function requestGeneration(button, status, done) {
        if (listenBusy || reviewToken === null || lastAudio === null || !lastAudio.generated) {
            return;
        }

        var generated = lastAudio.generated;
        var label = button.textContent;
        listenBusy = true;
        button.disabled = true;
        button.textContent = 'Queuing…';

        // The header control too, at once. It is behind a modal dialog and cannot be clicked from here,
        // but the re-read below is a round trip and this is the control the operator is watching — it
        // should not still read "Regenerate AI Audio" for the moment it takes the server to answer.
        // Replaced by whatever the server then says, in its words, not left on this guess.
        headerAction(function (control) {
            control.disabled = true;
            control.textContent = 'Queued…';
        });

        var body = new URLSearchParams();
        body.set('output_type', generated.outputType);
        body.set('expected_hash', generated.expectedHash);

        fetch(generated.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': reviewToken.value
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(
                function (data) { return data; },
                function () {
                    return { success: false, message: 'The server could not confirm this request.' };
                }
            );
        }).then(function (data) {
            listenBusy = false;
            button.disabled = false;
            button.textContent = label;
            say(status, data.message);

            // Re-read rather than guess: the state the panel shows next is the server's, and a
            // refusal leaves the panel exactly as it was. The message is carried into the re-read so
            // the confirmation survives the repaint that would otherwise clear it.
            if (data.success) {
                renderReview(data.message, reviewScroll ? reviewScroll.scrollTop : 0);

                if (typeof done === 'function') {
                    done();
                }

                return;
            }

            // Refused. The header was moved to "Queued…" the moment this was sent, on the assumption
            // that it would be accepted, so it has to be put back — nothing was queued, and a control
            // left disabled would be the second thing this request broke.
            restoreHeaderAction();
        }).catch(function () {
            listenBusy = false;
            button.disabled = false;
            button.textContent = label;
            say(status, 'Connection interrupted. Nothing was queued — try again.');
            restoreHeaderAction();
        });
    }

    /** Put the header control back to whatever the last read from the server said it was. */
    function restoreHeaderAction() {
        if (lastFragment !== null) {
            paintRecordingActions(lastFragment);
        }
    }

    /* ---- System audio: the browser's own voice ---------------------------------------------- */

    // The one authoritative gap between two spoken messages. Named, and named once: the pause is a
    // reading decision, and a reading decision scattered across three call sites is three decisions.
    var SYSTEM_AUDIO_GAP_MS = 1500;

    // Every piece of playback state, in one place, so Stop and a closing dialog have one thing to
    // clear. `timer` is the gap between messages and is cancelled alongside the speech — without
    // that, pausing during a gap would let the next line start anyway.
    //
    // `phase` is this module's own answer to "where is playback right now", and it exists because the
    // browser's is not trustworthy for it. `speechSynthesis.speaking` and `.paused` disagree between
    // engines — and, more to the point, there is a real moment when playback is *between* two
    // utterances, which the browser cannot describe at all because as far as it is concerned nothing
    // is happening. Pause has to behave differently in that moment, so the moment has to be named:
    //
    //   'idle'      nothing started, or everything torn down
    //   'speaking'  an utterance is with the engine     (+ paused -> PAUSED_WHILE_SPEAKING)
    //   'gap'       between two messages, timer pending (+ paused -> PAUSED_DURING_GAP)
    //   'finished'  the last message was read to its end
    //
    // The browser's own flags are still used — but to *drive* the engine, never to decide what should
    // happen next.
    var speech = {
        index: 0,
        turns: [],
        timer: null,
        playing: false,
        paused: false,
        phase: 'idle',
        status: null
    };
    var speechSupported = typeof window.speechSynthesis !== 'undefined'
        && typeof window.SpeechSynthesisUtterance === 'function';

    function speechControls() {
        if (!speechSupported) {
            return [el(
                'span',
                'a2t-listen__note',
                'System voice playback is not available in this browser.'
            )];
        }

        var controls = [];

        // Icon-only, because Play/Pause/Resume/Stop are the four glyphs every transport in the world
        // already uses, and four words here would cost more width than the players beside them. Each
        // one is still named twice — `title` for a pointer, `aria-label` for a screen reader — since
        // an icon with no name is a button that only its author can use.
        [
            { op: 'play', title: 'Play', label: 'Play system audio' },
            { op: 'pause', title: 'Pause', label: 'Pause system audio' },
            { op: 'resume', title: 'Resume', label: 'Resume system audio' },
            { op: 'stop', title: 'Stop', label: 'Stop system audio' }
        ].forEach(function (action) {
            var button = iconButton(action.op, action.title);
            button.setAttribute('data-a2t-speak', action.op);
            // `aria-label` is the accessible name now, so the helper's hidden span would be a second
            // one saying something shorter. Removed rather than left to be ignored.
            button.setAttribute('aria-label', action.label);
            var hidden = button.querySelector('.a2t-sr');
            if (hidden) {
                hidden.remove();
            }
            controls.push(button);
        });

        var rate = document.createElement('select');
        rate.className = 'field__control a2t-listen__rate';
        rate.setAttribute('data-a2t-speak-rate', '');
        rate.setAttribute('aria-label', 'Reading speed');
        [['0.75', '0.75×'], ['1', '1×'], ['1.25', '1.25×']].forEach(function (choice) {
            var option = document.createElement('option');
            option.value = choice[0];
            option.textContent = choice[1];
            // 1× unless the reader chose otherwise earlier in this session.
            option.selected = choice[0] === String(speechRate);
            rate.appendChild(option);
        });
        controls.push(rate);

        // A word, not a control: muted, and carrying its dot in CSS so what a screen reader reads and
        // what this element's text says stay the one word.
        speech.status = el('span', 'a2t-listen__state a2t-listen__status', 'Ready');
        speech.status.setAttribute('role', 'status');
        speech.status.setAttribute('aria-live', 'polite');
        controls.push(speech.status);

        return controls;
    }

    // Kept for the page's lifetime only, never stored: it is a reading preference for this sitting.
    var speechRate = 1;

    function speechSay(state) {
        if (speech.status) {
            speech.status.textContent = state;
        }
    }

    /** Clear the highlight from whichever turn had it. */
    function speechUnmark() {
        if (!reviewScroll) {
            return;
        }
        Array.from(reviewScroll.querySelectorAll('.a2t-turn--speaking')).forEach(function (turn) {
            turn.classList.remove('a2t-turn--speaking');
        });
    }

    /**
     * Stop everything: the utterance being spoken, the gap waiting to start the next one, the mark.
     *
     * Called by Stop, by a dialog closing, and before any new playback starts. One function, because
     * three near-identical teardowns is how a stray timer survives a closed dialog and starts talking
     * over the next recording.
     */
    function speechStop() {
        clearTimeout(speech.timer);
        speech.timer = null;
        speech.playing = false;
        speech.paused = false;
        speech.phase = 'idle';
        speech.index = 0;
        speech.turns = [];
        speechUnmark();

        if (speechSupported) {
            window.speechSynthesis.cancel();
            // `cancel()` empties the queue but does not lift a pause, and an engine left paused
            // accepts the next `speak()` without ever playing it. So a reading stopped while paused
            // would silently poison every later one — including the next recording's, and the next
            // dialog's. Lifted here, where every teardown already passes.
            if (window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
            }
        }

        speechSay('Ready');
    }

    /**
     * The messages as they stand on screen, in the order they are drawn.
     *
     * Read out of the rendered bubbles rather than out of the payload, and that is the whole rule:
     * what is spoken is what the administrator is looking at, corrections included. Nothing else in
     * the bubble is taken — not the speaker name, not the timing, not the edited flag — because none
     * of it was said out loud by anybody.
     */
    function speechCollect() {
        if (!reviewScroll) {
            return [];
        }

        return Array.from(reviewScroll.querySelectorAll('[data-a2t-turn]'))
            .map(function (turn) {
                var body = turn.querySelector('[data-a2t-text]');

                return { turn: turn, text: body ? body.textContent.trim() : '' };
            })
            .filter(function (entry) { return entry.text !== ''; });
    }

    function speechNext() {
        if (!speech.playing || speech.paused) {
            return;
        }

        if (speech.index >= speech.turns.length) {
            speechUnmark();
            speech.playing = false;
            speech.phase = 'finished';
            speech.timer = null;
            speechSay('Finished');

            return;
        }

        var entry = speech.turns[speech.index];
        speechUnmark();
        entry.turn.classList.add('a2t-turn--speaking');

        // Only when it is not already on screen, and gently: a reader scrolling back through a call
        // should not be dragged forward by the line being read.
        if (typeof entry.turn.scrollIntoView === 'function') {
            var box = entry.turn.getBoundingClientRect();
            var frame = reviewScroll.getBoundingClientRect();

            if (box.top < frame.top || box.bottom > frame.bottom) {
                entry.turn.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }

        var utterance = new window.SpeechSynthesisUtterance(entry.text);
        utterance.rate = speechRate;
        utterance.onend = function () {
            // A cancelled utterance also ends. `playing` is already false by then, so a teardown
            // cannot advance the index on its way out.
            if (!speech.playing) {
                return;
            }
            // The one place the index moves, and it moves once per utterance that reached its end.
            // Neither Pause nor Resume touches it: a held line has not ended, so it has not counted.
            speech.index++;
            speech.phase = 'gap';
            // The gap between messages. Held in `speech.timer` so Pause and Stop can cancel it —
            // a pause during the gap must not let the next line start anyway.
            speech.timer = setTimeout(speechNext, SYSTEM_AUDIO_GAP_MS);
        };
        utterance.onerror = function () {
            speechStop();
            speechSay('Stopped');
        };

        speech.phase = 'speaking';
        window.speechSynthesis.speak(utterance);
        speechSay('Speaking');
    }

    function speechPlay() {
        if (!speechSupported) {
            return;
        }

        // Already reading: a second press changes nothing. Restarting would throw away the reader's
        // place for a press they almost certainly did not mean, and Stop is next to it if they did.
        if (speech.playing && !speech.paused) {
            return;
        }

        // Whatever was talking — a paused session, or the last dialog's — stops first. Two queues at
        // once is the failure this guards against, and the browser's own queue would allow it.
        speechStop();

        speech.turns = speechCollect();

        if (speech.turns.length === 0) {
            speechSay('Nothing to read');

            return;
        }

        speech.playing = true;
        speech.index = 0;
        speechNext();
    }

    /**
     * Hold playback where it is — which is two different places, handled two different ways.
     *
     * Mid-utterance the engine is holding a line and its position within it, so the engine is what
     * pauses. Mid-gap it is holding nothing: pausing it there would be pausing an idle engine, and an
     * idle engine that has been paused still refuses the *next* `speak()` — it queues the utterance
     * and never plays it. That was the bug. So in the gap only our own timer is cancelled and the
     * engine is left untouched.
     *
     * The index is not moved either way. A held line has not ended, so it has not counted.
     */
    function speechPause() {
        if (!speech.playing || speech.paused) {
            return;
        }

        speech.paused = true;

        if (speech.phase === 'gap') {
            // Nothing is being spoken. Cancel only the pending start of the next line.
            clearTimeout(speech.timer);
            speech.timer = null;
        } else {
            window.speechSynthesis.pause();
        }

        speechSay('Paused');
    }

    /**
     * Carry on from wherever the pause landed, decided by our own phase rather than the engine's.
     *
     * `speechSynthesis.speaking` and `.paused` are read differently by different engines — some
     * report a held utterance as not speaking — and a wrong reading here is not cosmetic: it either
     * starts a second utterance for a line already half-read, or leaves the reading stopped for good.
     * So the phase decides, and the engine is only told what to do about it.
     */
    function speechResume() {
        if (!speech.playing || !speech.paused) {
            return;
        }

        speech.paused = false;
        speechSay('Speaking');

        if (speech.phase === 'gap') {
            // Between messages: nothing was paused, so nothing is resumed — the gap is simply re-armed
            // and the next line follows it. `speechNext()` is deliberately not called straight away:
            // the pause is part of the reading, and skipping it here would run two messages together.
            //
            // The engine is only nudged if something else left it paused, because a `speak()` issued
            // to a paused engine is silently queued for ever.
            if (window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
            }

            speech.timer = setTimeout(speechNext, SYSTEM_AUDIO_GAP_MS);

            return;
        }

        // Mid-utterance: the engine still holds the line and where it had got to, so it continues it.
        // Nothing is spoken again from here — a second `speak()` for the same line is exactly the
        // duplicate this avoids.
        window.speechSynthesis.resume();
    }

    /**
     * @param {boolean} fromCache true only when switching back to a channel already read in this
     *                            dialog. Every other caller re-reads, which is what keeps a cached
     *                            payload from outliving a correction or a generation.
     */
    function renderReview(message, resumeAt, fromCache) {
        if (reviewUrl === null) {
            return;
        }
        var requested = reviewUrl;

        if (fromCache === true && channelCache[requested] !== undefined) {
            paintReview(channelCache[requested], requested, message, resumeAt, true);

            return;
        }

        say(reviewStatus, message || 'Loading transcript…');

        load(requested).then(function (data) {
            if (reviewUrl !== requested) {
                return; // The dialog moved on to another recording while this was in flight.
            }

            // Overwrites whatever was cached for this recording, so the entry is always the newest
            // answer the server gave rather than the first one.
            channelCache[requested] = data;
            paintReview(data, requested, message, resumeAt);
        }).catch(function (error) {
            if (reviewUrl === requested) {
                fail(reviewStatus, error.message, function () { renderReview(null, resumeAt); });
            }
        });
    }

    /** Draw one recording's payload into the dialog. Reached from a fresh read and from the cache. */
    function paintReview(data, requested, message, resumeAt, fromCache) {
        version = data.version;
        reviewMeta.textContent = [channelLabel, data.filename, data.provider, 'Version ' + data.version]
            .filter(function (part) { return part; })
            .join(' · ');

        reviewNotice(data);
        // Whatever was being read aloud belonged to the turns about to be replaced.
        speechStop();
        paintListen(data);
        empty(reviewScroll);
        var thread = el('div', 'a2t-thread');
        data.turns.forEach(function (turn) { thread.appendChild(reviewTurn(turn)); });
        reviewScroll.appendChild(thread);
        reviewBody.hidden = false;
        // Back to where the reader was, once the new turns have a height to scroll through.
        reviewScroll.scrollTop = resumeAt || 0;
        loadHistory(data.urls.history, requested, fromCache);

        if (message) {
            say(reviewStatus, message);
        } else {
            quiet(reviewStatus);
        }
    }

    /**
     * The revision dialogs, from the partial the correction page renders inline.
     *
     * Markup rather than data on purpose: the Before/After arrangement, the merge note and the
     * wording of every summary live in one template, and rebuilding them here would be the second
     * implementation this work exists to remove. The server escapes every historical word on the way
     * out, exactly as it does for the page.
     *
     * Re-fetched after each read rather than once, because a correction *creates* history — a message
     * with no clock icon a moment ago has one now, and its dialog has to exist for it.
     */
    function loadHistory(url, requested, fromCache) {
        if (!historyHost || !url) {
            return;
        }

        if (fromCache === true && historyCache[url] !== undefined) {
            historyHost.innerHTML = historyCache[url];

            return;
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.ok ? response.text() : '';
        }).then(function (html) {
            // Overwritten rather than kept, for the same reason the payload is: a correction creates
            // history, so the newest answer is the only one worth remembering.
            historyCache[url] = html;

            // Another recording may have been opened while this was in flight; its dialogs win.
            if (reviewUrl === requested) {
                historyHost.innerHTML = html;
            }
        }).catch(function () {
            // A missing revision trail is not worth interrupting a correction for. The icon that
            // opens nothing is the only symptom, and the full editor still shows it.
        });
    }

    /**
     * Open the dialog on one recording — the single-recording path, unchanged.
     *
     * The tab strip is cleared and hidden, so this reads exactly as it did before orders could be
     * opened: one recording, its own title, no way to reach another.
     */
    function openReview(button) {
        orderLabel = null;
        showChannels([], null);

        openReviewOn(
            button.getAttribute('data-a2t-details'),
            button.getAttribute('data-a2t-details-label') || 'Recording details',
            button.getAttribute('data-a2t-details-full'),
        );
    }

    /**
     * Open the dialog on a whole order, with a tab per recording of the call.
     *
     * The channels come from the Details buttons THIS ROW already rendered, and only the ones marked
     * `data-a2t-current-channel`. That is not a shortcut around the server: those buttons are the
     * server's own answer to "what can be opened here", built from the group's current recordings — the
     * newest FINISHED version of each kind — and a button exists only where there is something to show.
     * Re-asking an endpoint for the same three labels would be a second round trip that could only
     * agree with what is already on the page.
     *
     * The marker is what separates a channel from an upload. A side recorded twice renders its
     * superseded version in the same cell, inside the fold that Manage Audio opens, and those carry a
     * Details button too — collecting every one of them would offer two tabs both called "Customer".
     *
     * Its VALUE is the channel's position, so the tabs are ordered by what they are rather than by
     * where they were drawn. See `channelsIn`.
     *
     * Document order is the priority the request asked for, because the columns are in that order:
     * Mix / Common, then Customer, then Agent. Nothing sorts it.
     *
     * ONE fetch happens here — the default channel's. The others are fetched when their tab is chosen,
     * and then only once. See `channelCache`.
     */
    function openOrder(button) {
        var channels = channelsIn(button.closest('tr'));

        if (channels.length === 0) {
            return; // The order id is not rendered as a way in without one; belt and braces.
        }

        orderLabel = 'Order #' + button.getAttribute('data-a2t-order-open');
        showChannels(channels, channels[0]);
        openReviewOn(channels[0].url, orderLabel, channels[0].full, channels[0].label);
    }

    /**
     * Draw the tab strip, or take it away.
     *
     * Hidden below two, which is the rule the Original transcript dialog above already follows: an
     * order with one recording opens straight onto it, and a strip holding a single tab would be a
     * control that cannot do anything.
     */
    function showChannels(channels, selected) {
        // A different call, so nothing read for the last one may be reused.
        channelCache = {};
        historyCache = {};

        if (!reviewTabs) {
            return;
        }

        empty(reviewTabs);
        reviewTabs.hidden = channels.length < 2;

        if (channels.length < 2) {
            return;
        }

        channels.forEach(function (channel) {
            var tab = el('button', 'a2t-tab', channel.label);
            tab.type = 'button';
            tab.setAttribute('role', 'tab');
            // Set at creation, so a tab nobody has pressed yet still says what it is rather than
            // carrying no state until the first switch.
            tab.setAttribute('aria-selected', channel === selected ? 'true' : 'false');
            tab.addEventListener('click', function () { chooseChannel(channel, tab); });
            reviewTabs.appendChild(tab);
        });
    }

    /**
     * Switch the dialog to another recording of the same call, without closing it.
     *
     * Served from `channelCache` when this channel has been read once already, so Customer → Agent →
     * Customer is two requests rather than three. The cache holds only what a tab switch put there and
     * is thrown away when the dialog closes; every action that CHANGES a recording — a correction, a
     * generation, the watcher following one — re-reads and overwrites its entry, so a cached payload
     * can never be older than the last thing that happened to it.
     */
    function chooseChannel(channel, tab) {
        if (reviewUrl === channel.url) {
            return; // Already showing. Re-rendering would only lose the reader's scroll position.
        }

        Array.from(reviewTabs.children).forEach(function (other) {
            other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
        });

        openReviewOn(channel.url, orderLabel || channel.label, channel.full, channel.label, true);
    }

    /**
     * Everything both ways in have in common: point the dialog at one recording and read it.
     *
     * @param {string}  url        the recording's fragment endpoint
     * @param {string}  title      what the dialog is about — a recording, or the order it belongs to
     * @param {?string} full       where "Open full editor" goes for this recording
     * @param {?string} label      the channel's own name, shown in the meta line when tabs are in play
     * @param {boolean} fromCache  whether a payload already read for this url may be reused
     */
    function openReviewOn(url, title, full, label, fromCache) {
        reviewUrl = url;
        channelLabel = label || null;
        version = -1;
        busy = false;
        reviewTitle.textContent = title;
        reviewMeta.textContent = '';
        fullEditor.href = full;
        empty(reviewNoticeBox);
        empty(reviewScroll);
        // A dialog opening on another recording must not inherit the last one's audio, spoken or
        // otherwise. `openDialog` reaches here before the fetch returns, so this is the earliest
        // point at which the previous recording's speech can be stopped.
        speechStop();
        stopWatchingGenerated();
        if (reviewActions) {
            // Emptied with the panel, not left showing the previous recording's buttons over a dialog
            // that is still loading — those buttons would act on whatever `lastFragment` still held.
            empty(reviewActions);
        }
        if (listen) {
            empty(listen);
            listen.hidden = true;
            // The dialogs that open on top of this one read these. Cleared here rather than left to be
            // overwritten by the fetch, so a press during the load cannot act on the last recording.
            lastAudio = null;
            lastFragment = null;
        }
        reviewBody.hidden = true;
        openDialog(reviewDialog);
        renderReview(null, 0, fromCache === true);
    }

    /**
     * Continue where the reader was after a reload that was not their idea.
     *
     * The Update dialog reloads the page when a replacement finishes, because the row behind it is
     * server-rendered and nothing re-renders one row. That would otherwise drop the reader back on a
     * table, having lost the transcript they were reading and with no clue which of three recordings had
     * just been replaced. The fragment names the recording to reopen.
     *
     * Only ever a 32-hex public id, and only ever used to find a button the server already rendered: a
     * fragment naming a recording that is not on this page opens nothing, which is the right answer for a
     * hand-edited URL as much as for a recording that has since been filtered out of the list.
     */
    function openRequestedRecording() {
        var wanted = /^#a2t-recording=([0-9a-f]{32})$/.exec(window.location.hash);

        if (!wanted) {
            return;
        }

        // Consumed: a later reload of this URL must not spring the dialog open again.
        window.history.replaceState(null, '', window.location.pathname + window.location.search);

        var button = document.querySelector('[data-a2t-details*="' + wanted[1] + '"]');

        if (!button) {
            return;
        }

        // Back into the order view when that is where the reader was — the row's own order id, then the
        // tab whose recording this is. Update Audio reloads the page because the row is server-rendered
        // and nothing re-renders one row, and dropping the reader into a single recording afterwards
        // would take away the other two channels they had open a moment earlier.
        var row = button.closest('tr');
        var order = row === null ? null : row.querySelector('[data-a2t-order-open]');

        if (order === null) {
            openReview(button);

            return;
        }

        openOrder(order);
        selectChannelFor(button.getAttribute('data-a2t-details'));
    }

    /** Move the open order dialog to the tab holding one recording, if it is not already there. */
    function selectChannelFor(url) {
        if (!reviewTabs || reviewTabs.hidden || reviewUrl === url) {
            return;
        }

        Array.from(reviewTabs.children).forEach(function (tab, index) {
            if (index === channelIndexOf(url)) {
                tab.click();
            }
        });
    }

    /** Which tab a recording's url belongs to. Through `channelsIn`, so it agrees with what was drawn. */
    function channelIndexOf(url) {
        var control = document.querySelector('[data-a2t-details="' + url + '"]');

        return channelsIn(control === null ? null : control.closest('tr'))
            .map(function (channel) { return channel.url; })
            .indexOf(url);
    }

    /**
     * The channels one row offers, in the order their tabs belong in.
     *
     * Ordered by the rank the SERVER put on each marker — 0 mixed, 1 the customer's side, 2 the
     * agent's — and never by where the button sits. The three named columns happen to render in that
     * order, but a legacy Customer + Agent pair puts BOTH of its halves in the first cell, in the order
     * their jobs were inserted; ordering by position would let whichever was enqueued first lead, and
     * would place them under the column that holds them rather than under what they are.
     *
     * One channel per rank. Two recordings claiming the same position would be two tabs with one name,
     * and the first drawn is the one the row leads with.
     */
    function channelsIn(row) {
        if (row === null) {
            return [];
        }

        var byRank = [];

        Array.from(row.querySelectorAll('[data-a2t-current-channel]')).forEach(function (control) {
            var rank = parseInt(control.getAttribute('data-a2t-current-channel'), 10);

            if (isNaN(rank) || byRank[rank] !== undefined) {
                return;
            }

            byRank[rank] = {
                rank: rank,
                url: control.getAttribute('data-a2t-details'),
                label: control.getAttribute('data-a2t-details-label') || 'Recording',
                full: control.getAttribute('data-a2t-details-full')
            };
        });

        // A sparse array indexed by rank: filtering it yields ascending order with no sort to get wrong,
        // and an order with no mixed recording simply has nothing at 0.
        return byRank.filter(function (channel) { return channel !== undefined; });
    }

    if (reviewDialog) {
        reviewDialog.addEventListener('close', function () {
            reviewUrl = null;
            picked = null;
            // Closing is the commonest way to leave, and the one where a voice left talking to an
            // empty screen would be most obviously wrong.
            speechStop();
            // Nothing to keep asking for once there is nowhere to show the answer.
            stopWatchingGenerated();
            // And nothing read for this call survives it: the next open re-reads, so a recording that
            // changed while the dialog was shut is never shown from memory.
            channelCache = {};
            historyCache = {};
            orderLabel = null;
            channelLabel = null;
        });

        openRequestedRecording();
    }

    /* ---- The correction controls, driven by the shared module ------------------------------ */

    // Highlighting a message's own words selects it and reveals its merge strip; highlighting
    // nothing, or across two bubbles, clears it. The same rule the correction page applies, because
    // it is the same function — and the reason a plain click selects nothing on either screen.
    document.addEventListener('selectionchange', function () {
        if (reviewUrl === null || !reviewScroll) {
            return;
        }
        picked = turns.showControlsFor(reviewScroll, turns.selectionInsideOneTurn());
    });

    if (moveParts !== null) {
        moveForm.addEventListener('submit', function (event) {
            event.preventDefault();
            turns.endConfirm(reviewScroll, moveDialog);
            submitForm(moveForm);
        });
    }

    if (mergeParts !== null) {
        mergeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            turns.endConfirm(reviewScroll, mergeDialog);
            submitForm(mergeForm);
        });
    }

    /**
     * Everything inside the Details dialog that is a control rather than a bubble.
     *
     * Listened for on the dialog rather than the document so a click here can stop travelling before
     * the page's own delegated handler sees it — and so none of it reaches the bubble underneath.
     */
    if (reviewDialog) {
        reviewDialog.addEventListener('click', function (event) {
            var target = event.target;
            // Element rather than HTMLElement: a click on the inline <svg> inside an icon button is
            // an SVGElement and would otherwise be ignored, so the middle of an icon would not respond.
            if (!(target instanceof Element)) {
                return;
            }

            var speak = target.closest('[data-a2t-speak]');
            if (speak) {
                event.preventDefault();
                var op = speak.getAttribute('data-a2t-speak');
                if (op === 'play') {
                    speechPlay();
                } else if (op === 'pause') {
                    speechPause();
                } else if (op === 'resume') {
                    speechResume();
                } else {
                    speechStop();
                }
                return;
            }

            // Both open a dialog on top of this one rather than acting on the press. Replacing a
            // recording and paying a provider are the two things in here that cannot be undone, and
            // neither should happen on a single click inside a transcript somebody is reading.
            if (target.closest('[data-a2t-update-open]')) {
                event.preventDefault();
                openUpdate();
                return;
            }

            if (target.closest('[data-a2t-tts-confirm]')) {
                event.preventDefault();
                openTtsConfirm();
                return;
            }

            var move = target.closest('[data-a2t-move]');
            if (move && moveParts !== null) {
                event.preventDefault();
                turns.openMove(reviewScroll, moveParts, turns.turnOf(move));
                return;
            }

            var edit = target.closest('[data-a2t-edit]');
            if (edit) {
                event.preventDefault();
                turns.openEditor(turns.turnOf(edit));
                return;
            }

            var cancelEdit = target.closest('[data-a2t-edit-cancel]');
            if (cancelEdit) {
                event.preventDefault();
                turns.closeEditor(turns.turnOf(cancelEdit));
                return;
            }

            var history = target.closest('[data-a2t-history]');
            if (history) {
                event.preventDefault();
                var dialog = historyHost
                    ? historyHost.querySelector(
                        '[data-a2t-history-dialog="' + history.getAttribute('data-a2t-history') + '"]'
                    )
                    : null;
                if (dialog) {
                    openDialog(dialog);
                }
                return;
            }

            var mergeWith = target.closest('[data-a2t-merge-with]');
            if (mergeWith && mergeParts !== null) {
                event.preventDefault();
                turns.openMerge(
                    reviewScroll,
                    mergeParts,
                    turns.turnOf(mergeWith),
                    mergeWith.getAttribute('data-a2t-merge-with'),
                    picked,
                );
            }
        });

        reviewDialog.addEventListener('change', function (event) {
            var rate = event.target.closest
                ? event.target.closest('[data-a2t-speak-rate]')
                : null;

            if (!rate) {
                return;
            }

            speechRate = parseFloat(rate.value) || 1;

            // Applied to the next message rather than the current one: an utterance's rate is fixed
            // once the browser has taken it, and restarting the line to honour a slider would lose
            // the reader's place mid-sentence.
            if (speech.playing) {
                speechSay('Speaking');
            }
        });

        // The inline editor is a real form; it is sent the same way the confirmations are.
        reviewDialog.addEventListener('submit', function (event) {
            var editor = event.target.closest
                ? event.target.closest('[data-a2t-editor]')
                : null;

            if (editor) {
                event.preventDefault();
                submitForm(editor);
            }
        });
    }

    // Cancel on either confirmation puts the conversation back as it was and writes nothing.
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element) || reviewUrl === null) {
            return;
        }

        if (target.closest('[data-a2t-move-cancel]')) {
            event.preventDefault();
            turns.endConfirm(reviewScroll, moveDialog);
        } else if (target.closest('[data-a2t-merge-cancel]')) {
            event.preventDefault();
            turns.endConfirm(reviewScroll, mergeDialog);
        } else if (target.closest('[data-a2t-history-close]')) {
            event.preventDefault();
            closeDialog(target.closest('dialog'));
        }
    });

    /* ---- One delegated listener for the whole table ---------------------------------------- */

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var play = target.closest('[data-a2t-play]');
        if (play) {
            event.preventDefault();
            toggle(play);
            return;
        }

        var opener = target.closest('[data-a2t-open]');
        if (opener) {
            var wanted = document.getElementById(opener.getAttribute('data-a2t-open'));
            if (wanted && wanted.hasAttribute('data-a2t-dialog')) {
                // The link's href is a server-answerable `?upload=1`, and stays the fallback.
                event.preventDefault();
                openDialog(wanted);
            }
            return;
        }

        var closer = target.closest('[data-a2t-dialog-close]');
        if (closer) {
            event.preventDefault();
            closeDialog(dialogOf(closer));
            return;
        }

        var history = target.closest('[data-a2t-history]');
        if (history) {
            event.preventDefault();
            openHistory(history);
            return;
        }

        var details = target.closest('[data-a2t-details]');
        if (details && reviewDialog) {
            event.preventDefault();
            openReview(details);
            return;
        }

        // The order id. Checked after the Details buttons rather than before, because both live in the
        // same row and the more specific control must win if the two ever nest.
        var order = target.closest('[data-a2t-order-open]');
        if (order && reviewDialog) {
            event.preventDefault();
            openOrder(order);
            return;
        }

        var transcripts = target.closest('[data-a2t-transcripts]');
        if (transcripts && transcriptDialog) {
            event.preventDefault();
            openTranscripts(transcripts);
            return;
        }

        var tts = target.closest('[data-a2t-tts]');
        if (tts && ttsDialog) {
            event.preventDefault();
            openTts(tts);
            return;
        }

        var manage = target.closest('[data-a2t-manage]');
        if (manage && manageDialog) {
            event.preventDefault();
            openManage(manage);
            return;
        }

        var replace = target.closest('[data-a2t-replace]');
        if (replace) {
            event.preventDefault();
            var slot = replace.closest('.a2t-manage__slot');
            var form = slot ? slot.querySelector('[data-a2t-replace-form]') : null;
            if (form) {
                form.hidden = !form.hidden;
            }
            return;
        }

        var cancelReplace = target.closest('[data-a2t-replace-cancel]');
        if (cancelReplace) {
            event.preventDefault();
            var owner = cancelReplace.closest('[data-a2t-replace-form]');
            if (owner) {
                // `reset()` restores the *rendered* defaults, which is what we want for all three
                // fields: no file, the provider the server chose, and an unticked paid box.
                owner.reset();
                owner.hidden = true;
            }
            return;
        }

        // A click on the dialog element itself is a click on its backdrop: the children sit inside it.
        if (target.matches('[data-a2t-dialog]')) {
            closeDialog(target);
        }
    });
}());
