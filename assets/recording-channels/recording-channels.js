/*
 * Recording channel test — field-level validation for the Time field.
 *
 * A UX layer and nothing else. The server still validates every value in
 * `ChannelRecordingRequest::validate()` before a URL is built, and this file cannot weaken that: with
 * JavaScript off, or if this script fails to load, the form submits exactly as it always did and the
 * server refuses a bad date exactly as it always did.
 *
 * ## Why an external file rather than an inline <script>
 *
 * The content-security policy is `script-src 'self'` with no 'unsafe-inline', so an inline handler
 * would simply not run. Everything here attaches with addEventListener.
 *
 * ## Why the rule is duplicated here
 *
 * It has to be: the browser cannot call PHP. What is NOT duplicated is the *message* — it is read from
 * the `data-time-message` attribute the template renders from
 * `ChannelRecordingRequest::TIME_FORMAT_MESSAGE`, so the sentence a user sees before submitting and the
 * one the server would return are the same string by construction rather than by memory.
 *
 * The rule mirrors `ChannelRecordingRequest::isCalendarDate()`: the exact shape YYYY-MM-DD, and a REAL
 * calendar date. `2026-02-31` has the right shape and does not exist, so it is refused in both places.
 * Leap years follow from asking the Date object rather than from counting days here.
 */
(function () {
    'use strict';

    var SHAPE = /^\d{4}-\d{2}-\d{2}$/;

    /**
     * The exact shape, and a date the calendar actually has.
     *
     * The year is set with setUTCFullYear rather than through Date.UTC() because Date.UTC maps years
     * 0-99 onto 1900-1999, which would make this stricter than the server for a value the server
     * accepts. Client-side validation must never refuse something the request would have succeeded
     * with.
     */
    function isCalendarDate(value) {
        if (!SHAPE.test(value)) {
            return false;
        }

        var year = Number(value.slice(0, 4));
        var month = Number(value.slice(5, 7));
        var day = Number(value.slice(8, 10));

        var date = new Date(0);
        date.setUTCFullYear(year, month - 1, day);
        date.setUTCHours(0, 0, 0, 0);

        // A rolled-over date is the tell: February 31st comes back as March 3rd, and 2025-02-29 as
        // March 1st. Comparing all three components catches both without a leap-year rule of its own.
        return date.getUTCFullYear() === year
            && date.getUTCMonth() === month - 1
            && date.getUTCDate() === day;
    }

    function setUp(input) {
        var form = input.form;
        var error = document.getElementById(input.getAttribute('aria-errormessage') || '');
        var message = input.getAttribute('data-time-message') || '';

        if (!form || !error) {
            return;
        }

        // True once the field has been judged: on first load a half-typed date must not be shouted at,
        // but after a blur or a refused submit the message follows every keystroke until it is right.
        // The server may have already judged it, in which case the message is on screen and live.
        var judged = !error.hidden;

        function show() {
            error.textContent = message;
            error.hidden = false;
            input.classList.add('field__control--error');
            input.setAttribute('aria-invalid', 'true');
        }

        function clear() {
            error.hidden = true;
            input.classList.remove('field__control--error');
            input.removeAttribute('aria-invalid');
        }

        function check() {
            var valid = isCalendarDate(input.value.trim());

            if (valid) {
                clear();
            } else {
                show();
            }

            return valid;
        }

        // While typing: only ever corrects a verdict that is already on screen. Clearing as soon as the
        // value becomes valid is the half that matters; flagging a date the moment someone types "2"
        // would be noise.
        input.addEventListener('input', function () {
            if (judged) {
                check();
            }
        });

        // Leaving the field, or picking a value some other way, is the point at which a verdict is fair.
        input.addEventListener('blur', function () {
            judged = true;
            check();
        });

        input.addEventListener('change', function () {
            judged = true;
            check();
        });

        // The submit guard. Stopping here means no request is sent to this application, so nothing is
        // sent to the external recording API either — the page action is the only thing that calls it.
        form.addEventListener('submit', function (event) {
            judged = true;

            if (!check()) {
                event.preventDefault();
                input.focus();
            }
        });
    }

    function init() {
        var inputs = document.querySelectorAll('[data-time-input]');

        for (var i = 0; i < inputs.length; i += 1) {
            setUp(inputs[i]);
        }
    }

    // Asset JS is emitted at the end of the body, so the field is already parsed; the guard covers a
    // future move into <head> rather than a situation that exists today.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
