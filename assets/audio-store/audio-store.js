/* Store-audio page only: the existing upload POST plus read-only conversion status polling. */
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
    var stages = {
        QUEUED: 'Waiting for the transcription worker',
        CLAIMED: 'Starting conversion',
        CONVERTING: 'Preparing audio for transcription',
        TRANSCRIBING: 'Transcribing audio to text',
        DIARIZING: 'Separating speakers',
        MAPPING_SPEAKERS: 'Identifying speakers',
        SAVING: 'Saving the transcript'
    };

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

            function trackConversion(statusUrl, destination, interval) {
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
                                stopped = true;
                                conversionStep.dataset.state = 'complete';
                                conversionProgress.value = 100;
                                conversionPercent.textContent = '100%';
                                conversionStatus.textContent = 'Conversion complete · opening result…';
                                state('complete', 'Complete');
                                button.textContent = 'Conversion complete';
                                // Keep the original result destination and do not touch the listing.
                                timer = setTimeout(function () { window.location.assign(destination.href); }, 1000);
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
                    var interval = Math.max(2000, pendingJob ? parseInt(pendingJob.dataset.a2tInterval, 10) || 2000 : 2000);
                    if (statusUrl.origin === window.location.origin) {
                        trackConversion(statusUrl, destination, interval);
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
