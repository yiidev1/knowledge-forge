/*
 * Knowledge Forge — minimal progressive enhancement.
 *
 * Self-hosted and tiny, so the strict `script-src 'self'` content-security policy needs no exceptions.
 * The app is fully usable with JavaScript disabled: destructive actions still confirm via the server
 * form, and chat still works as a normal POST. This script only adds nicer feedback on top:
 *   1. confirmation prompts on destructive actions (data-confirm),
 *   2. a ChatGPT-style "you asked … / assistant is thinking …" state while an answer is generated,
 *   3. Enter-to-send (Shift+Enter for a newline) in the chat box,
 *   4. auto-scroll of the message list to the newest message, with a "jump to latest" affordance.
 * Handlers attach by event delegation, not inline attributes, which keeps the CSP free of 'unsafe-inline'.
 */
(function () {
    'use strict';

    var NEAR_BOTTOM_PX = 90;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var submitting = false;

    // Roughly the provider timeout. Past this the request is unusually slow, so the indicator says so
    // instead of implying the page is stuck.
    var SLOW_ANSWER_NOTICE_MS = 45000;

    function messageContainer() {
        return document.querySelector('.chat__messages');
    }

    function isNearBottom(container) {
        return container.scrollHeight - container.scrollTop - container.clientHeight < NEAR_BOTTOM_PX;
    }

    function scrollToBottom(container, smooth) {
        if (!container) {
            return;
        }
        container.scrollTo({ top: container.scrollHeight, behavior: smooth && !reduceMotion ? 'smooth' : 'auto' });
    }

    function hideJump() {
        var jump = document.querySelector('.chat__jump');
        if (jump) {
            jump.setAttribute('hidden', '');
        }
    }

    // ---- Destructive-action confirm + chat submit feedback --------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }

        // Editing a question or retrying its answer also regenerates synchronously; give the submit an
        // optimistic "working" state, then let the browser navigate (PRG) as usual.
        if (form.hasAttribute('data-edit-form')) {
            showEditPending(form);
            return;
        }
        if (form.hasAttribute('data-retry-form')) {
            showRetryPending(form);
            return;
        }

        // If this is a chat form, show the question + a thinking indicator immediately. We do NOT cancel
        // the submit — the browser navigates as usual, and this state stays on screen during the
        // round-trip instead of a blank page with only the tab spinner.
        showChatPending(form);
    });

    function showChatPending(form) {
        var textarea = form.querySelector('textarea[name="question"]');
        if (!textarea) {
            return; // not a chat form
        }

        var text = textarea.value.replace(/\s+$/, '');
        if (text.trim() === '') {
            return; // empty — let the field's native "required" handling take over
        }
        if (submitting) {
            return; // guard against a duplicate submission
        }
        submitting = true;

        var container = messageContainer();
        var thread = document.querySelector('.chat-thread');
        if (!thread) {
            var host = container || form.parentNode;
            var empty = host.querySelector ? host.querySelector('.chat__empty') : null;
            if (empty) {
                empty.remove();
            }
            thread = document.createElement('div');
            thread.className = 'chat-thread';
            host.appendChild(thread);
        }

        thread.appendChild(buildMessage('user', 'You', text));
        thread.appendChild(buildThinking());
        scrollToBottom(container, false);
        hideJump();

        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
            button.textContent = 'Sending…';
        }
        textarea.setAttribute('readonly', 'readonly');

        // A long answer can take a while, and an indefinite "Thinking…" is indistinguishable from a
        // request that died. After the server's own budget has elapsed, say so — the browser is still
        // waiting on the POST, so this only sets expectations; it never cancels or resubmits anything.
        window.setTimeout(function () {
            var label = document.querySelector('.chat-msg--thinking .chat-typing__label');
            if (label) {
                label.textContent = 'Still working — this answer is taking longer than usual…';
            }
        }, SLOW_ANSWER_NOTICE_MS);
    }

    function buildMessage(role, label, text) {
        var msg = el('article', 'chat-msg chat-msg--' + role);
        msg.appendChild(el('div', 'chat-msg__role', label));
        var body = el('div', 'chat-msg__body');
        // textContent only — user input is never treated as HTML.
        text.split('\n').forEach(function (line, index) {
            if (index > 0) {
                body.appendChild(document.createElement('br'));
            }
            body.appendChild(document.createTextNode(line));
        });
        msg.appendChild(body);
        return msg;
    }

    function buildThinking() {
        var msg = el('article', 'chat-msg chat-msg--assistant chat-msg--thinking');
        msg.appendChild(el('div', 'chat-msg__role', 'Assistant'));
        var body = el('div', 'chat-msg__body');
        body.setAttribute('role', 'status');
        var typing = el('span', 'chat-typing');
        typing.appendChild(el('span', 'chat-typing__dot'));
        typing.appendChild(el('span', 'chat-typing__dot'));
        typing.appendChild(el('span', 'chat-typing__dot'));
        body.appendChild(typing);
        body.appendChild(el('span', 'chat-typing__label', 'Thinking…'));
        msg.appendChild(body);
        return msg;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text != null) {
            node.textContent = text;
        }
        return node;
    }

    // ---- Inline question editing + answer retry -------------------------------
    // Reveal/cancel the inline edit form on the latest question, and give the edit/retry submits an
    // optimistic "working" state. Delegated, so the strict CSP needs no inline handlers. The forms POST
    // and navigate (PRG) like the composer, so the page reloads with the regenerated answer; this only
    // sets expectations during the round-trip and never cancels the submit.
    document.addEventListener('click', function (event) {
        var target = event.target;
        // Element, not HTMLElement: a click on the inline <svg>/<path> inside the pencil button is an
        // SVGElement, and would otherwise be ignored — so the centre of the icon wouldn't respond.
        if (!(target instanceof Element)) {
            return;
        }

        var toggle = target.closest('[data-edit-toggle]');
        if (toggle) {
            event.preventDefault();
            toggleEdit(toggle.closest('.chat-msg'), true);
            return;
        }

        var cancel = target.closest('[data-edit-cancel]');
        if (cancel) {
            event.preventDefault();
            toggleEdit(cancel.closest('.chat-msg'), false);
        }
    });

    function toggleEdit(article, open) {
        if (!article) {
            return;
        }
        // A single state class drives visibility (body vs form, and the pencil) from CSS.
        article.classList.toggle('chat-msg--editing', open);
        if (open) {
            var textarea = article.querySelector('textarea[name="content"]');
            if (textarea) {
                textarea.focus();
                textarea.setSelectionRange(textarea.value.length, textarea.value.length);
            }
        }
    }

    function showEditPending(form) {
        if (form.dataset.submitting === '1') {
            return; // guard against a duplicate submission
        }
        form.dataset.submitting = '1';
        var textarea = form.querySelector('textarea[name="content"]');
        if (textarea) {
            textarea.setAttribute('readonly', 'readonly');
        }
        var cancel = form.querySelector('[data-edit-cancel]');
        if (cancel) {
            cancel.disabled = true;
        }
        setBusy(form.querySelector('button[type="submit"]'), 'Regenerating answer…');
    }

    function showRetryPending(form) {
        if (form.dataset.submitting === '1') {
            return;
        }
        form.dataset.submitting = '1';
        setBusy(form.querySelector('button[type="submit"]'), 'Regenerating answer…');
    }

    function setBusy(button, label) {
        if (button) {
            button.disabled = true;
            button.textContent = label;
        }
    }

    // ---- Copy-to-clipboard for long identifiers ------------------------------
    // Delegated, like every other handler here, so the Content-Security-Policy needs no
    // 'unsafe-inline'. The buttons ship with `hidden` set and are revealed only once this script runs,
    // so a browser without JavaScript never shows a control that could not work — the full identifier
    // is always available in the readonly field inside each row's details panel.
    window.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('button[data-copy]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].removeAttribute('hidden');
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        var button = target.closest('button[data-copy]');
        if (!button) {
            return;
        }

        event.preventDefault();

        var value = button.getAttribute('data-copy') || '';
        var original = button.textContent;

        function done(label) {
            button.textContent = label;
            window.setTimeout(function () {
                button.textContent = original;
            }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                done('Copied');
            }, function () {
                done('Press Ctrl+C');
            });
            return;
        }

        done('Press Ctrl+C');
    });

    // ---- Enter to send (Shift+Enter = newline) --------------------------------
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey) {
            return;
        }
        var target = event.target;
        if (!(target instanceof HTMLTextAreaElement) || target.name !== 'question') {
            return;
        }
        var form = target.form;
        if (!form) {
            return;
        }
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(); // triggers native validation + our submit handler
        } else {
            form.submit();
        }
    });

    // ---- Scroll behaviour: open at newest, toggle "jump to latest" ------------
    window.addEventListener('DOMContentLoaded', function () {
        var container = messageContainer();
        if (!container) {
            return;
        }

        // Open the conversation at the newest message.
        scrollToBottom(container, false);

        var jump = document.querySelector('.chat__jump');

        function updateJump() {
            if (!jump) {
                return;
            }
            if (isNearBottom(container)) {
                jump.setAttribute('hidden', '');
            } else {
                jump.removeAttribute('hidden');
            }
        }

        container.addEventListener('scroll', updateJump, { passive: true });
        if (jump) {
            jump.addEventListener('click', function () {
                scrollToBottom(container, true);
                jump.setAttribute('hidden', '');
            });
        }
        updateJump();

        bindLoadOlder(container);
    });

    /**
     * Cursor-based older history. User message bodies use textContent only; assistant HTML comes from
     * the server Markdown renderer (html_input=escape). Citation filenames use textContent.
     */
    function bindLoadOlder(container) {
        var root = document.querySelector('[data-chat-root]');
        if (!root) {
            return;
        }
        var historyUrl = root.getAttribute('data-history-url');
        if (!historyUrl) {
            return;
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            var button = target.closest('[data-load-older]');
            if (!button || !(button instanceof HTMLButtonElement)) {
                return;
            }
            event.preventDefault();
            if (button.disabled) {
                return;
            }

            var beforeId = button.getAttribute('data-before-id');
            if (!beforeId) {
                return;
            }

            button.disabled = true;
            var url = historyUrl + (historyUrl.indexOf('?') >= 0 ? '&' : '?') + 'before_message_id=' + encodeURIComponent(beforeId);

            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('history ' + response.status);
                }
                return response.json();
            }).then(function (payload) {
                var thread = container.querySelector('[data-chat-thread]');
                if (!thread) {
                    var empty = container.querySelector('[data-chat-empty]');
                    if (empty) {
                        empty.remove();
                    }
                    thread = document.createElement('div');
                    thread.className = 'chat-thread';
                    thread.setAttribute('data-chat-thread', '');
                    container.appendChild(thread);
                }

                var prevHeight = container.scrollHeight;
                var messages = payload.messages || [];
                var frag = document.createDocumentFragment();
                for (var i = 0; i < messages.length; i++) {
                    frag.appendChild(buildHistoryMessage(messages[i]));
                }
                thread.insertBefore(frag, thread.firstChild);
                container.scrollTop = container.scrollHeight - prevHeight;

                var wrap = button.closest('.chat__load-older');
                if (payload.has_older && messages.length > 0) {
                    button.disabled = false;
                    button.setAttribute('data-before-id', String(messages[0].id));
                } else if (wrap) {
                    wrap.remove();
                } else {
                    button.remove();
                }
            }).catch(function () {
                button.disabled = false;
            });
        });
    }

    function buildHistoryMessage(msg) {
        var isUser = msg.role === 'user';
        var article = el('article', 'chat-msg chat-msg--' + (isUser ? 'user' : 'assistant'));
        article.setAttribute('data-message-id', String(msg.id));
        article.appendChild(el('div', 'chat-msg__role', isUser ? 'You' : 'Assistant'));

        var body = el('div', 'chat-msg__body');
        if (isUser) {
            // Never assign untrusted content to innerHTML.
            String(msg.content || '').split('\n').forEach(function (line, index) {
                if (index > 0) {
                    body.appendChild(document.createElement('br'));
                }
                body.appendChild(document.createTextNode(line));
            });
        } else if (msg.html) {
            // Server-rendered Markdown with html_input=escape.
            body.innerHTML = msg.html;
        } else {
            body.appendChild(document.createTextNode(String(msg.content || '')));
        }
        article.appendChild(body);
        article.appendChild(el('div', 'chat-msg__time', String(msg.created_at || '')));

        if (!isUser) {
            var citations = msg.citations || [];
            if (citations.length > 0) {
                var box = el('div', 'chat-msg__citations');
                box.appendChild(el('span', 'chat-msg__citations-label', 'Sources (' + citations.length + ')'));
                for (var c = 0; c < citations.length; c++) {
                    var chip = el('span', 'chat-chip');
                    chip.appendChild(document.createTextNode(String(citations[c].filename || '')));
                    box.appendChild(chip);
                }
                article.appendChild(box);
            } else if (!msg.is_grounded) {
                var ung = el('div', 'chat-msg__citations util-muted');
                ung.appendChild(document.createTextNode('No cited source for this answer.'));
                article.appendChild(ung);
            }
        }

        return article;
    }
})();
