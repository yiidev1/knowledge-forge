/*
 * The searchable Store picker, in a real browser.
 *
 * Every claim this control makes is invisible to the Web suite, because the suite sees the markup the
 * server sent and the picker does not exist until a script builds it:
 *
 * - **it is built at all.** PhpBrowser sees a `<select>`; whether that select is ever replaced by a
 *   listbox is a statement about `DOMContentLoaded`, not about HTML.
 * - **it works inside a native `<dialog>`.** This is the reason Select2 was rejected — a dropdown
 *   appended to `<body>` paints behind a top-layer modal — so "the panel is visible and on top" is the
 *   check that justifies the whole decision. It is measured with `elementFromPoint`, not assumed.
 * - **the CSP is clean.** A library that writes inline `style` would be refused here; this records
 *   every `securitypolicyviolation` the page fires and expects none.
 * - **Escape closes the list and not the dialog.** One `stopPropagation()` is the only thing standing
 *   between dismissing a dropdown and throwing away a half-typed form.
 * - **search and preselection.** Typing narrows a real list; opening Edit shows the stored store.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/store-picker.js
 */
const { BASE, launch, signIn } = require('./lib');
const fx = require('./fixtures-topics.json');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** What the picker is currently showing, read out of the live DOM. */
async function picker(page) {
    return page.evaluate(() => {
        const host = document.querySelector('[data-practice-store]');

        if (host === null) {
            return { built: false };
        }

        const select = host.querySelector('[data-practice-store-select]');
        const panel = host.querySelector('[data-practice-store-panel]');
        const summary = host.querySelector('[data-practice-store-summary]');
        const options = Array.from(host.querySelectorAll('[data-practice-store-option]'));
        const shown = options.filter((o) => !o.hidden);

        return {
            built: summary !== null,
            selectHidden: select.hidden,
            // Hidden is not disabled: this is the field that still posts.
            selectDisabled: select.disabled,
            selectValue: select.value,
            selectName: select.getAttribute('name'),
            summaryText: summary === null ? null : summary.textContent.trim(),
            summaryType: summary === null ? null : summary.getAttribute('type'),
            expanded: summary === null ? null : summary.getAttribute('aria-expanded'),
            panelOpen: panel !== null && !panel.hidden,
            total: options.length,
            visible: shown.length,
            firstVisible: shown.length === 0 ? null : shown[0].textContent.trim(),
            activeText: (() => {
                const a = host.querySelector('[data-practice-store-option].is-active');
                return a === null ? null : a.textContent.trim();
            })(),
            role: host.querySelector('[data-practice-store-list]')?.getAttribute('role') ?? null,
        };
    });
}

/**
 * Is the open panel actually the thing a reader would click?
 *
 * The Select2 failure mode is a dropdown that exists, has a sensible box, and is painted *behind* the
 * modal. Only hit-testing a real point tells the two apart, so this asks the document what is at the
 * panel's own centre and checks the answer is inside the panel.
 */
async function panelOnTop(page) {
    return page.evaluate(() => {
        const panel = document.querySelector('[data-practice-store-panel]');

        if (panel === null || panel.hidden) {
            return { visible: false };
        }

        const box = panel.getBoundingClientRect();
        const hit = document.elementFromPoint(box.left + box.width / 2, box.top + 8);

        return {
            visible: box.width > 0 && box.height > 0,
            onTop: hit !== null && panel.contains(hit),
            width: Math.round(box.width),
            height: Math.round(box.height),
        };
    });
}

(async () => {
    const { browser, page, errors } = await launch();

    try {
        await signIn(page, fx);

        // ---------------------------------------------------------------- Add Topic
        await page.goto(BASE + '/admin/topics?topic=new', { waitUntil: 'networkidle0' });

        let state = await picker(page);
        check('the picker is built over the select', state.built === true);
        check('the native select is hidden but still submits',
            state.selectHidden === true && state.selectDisabled === false
            && state.selectName === 'store_source_id',
            'hidden=' + state.selectHidden + ' disabled=' + state.selectDisabled);
        check('it is a listbox', state.role === 'listbox', String(state.role));
        check('the button cannot submit the form', state.summaryType === 'button', String(state.summaryType));
        check('Global is the default', state.selectValue === '' && /Global/.test(state.summaryText),
            state.summaryText);
        check('every store is an option', state.total > 100, state.total + ' options');
        check('the panel starts closed', state.panelOpen === false && state.expanded === 'false');

        await page.click('[data-practice-store-summary]');
        state = await picker(page);
        check('clicking opens it', state.panelOpen === true && state.expanded === 'true');

        const geometry = await panelOnTop(page);
        check('the panel is visible inside the native dialog', geometry.visible === true,
            geometry.width + 'x' + geometry.height);
        check('the panel paints ON TOP of the modal, not behind it', geometry.onTop === true);

        // ---------------------------------------------------------------- searching
        const needle = fx.storeName.slice(0, 8);

        await page.type('[data-practice-store-search]', needle);
        state = await picker(page);
        check('typing narrows the list', state.visible < state.total && state.visible > 0,
            state.visible + ' of ' + state.total + ' for "' + needle + '"');
        check('the first match is the keyboard target', state.activeText === state.firstVisible,
            String(state.activeText));

        await page.keyboard.press('Enter');
        state = await picker(page);
        check('Enter chooses the match', state.selectValue === String(fx.store),
            'value=' + state.selectValue + ' wanted=' + fx.store);
        check('choosing closes the panel', state.panelOpen === false);
        check('the button shows what was chosen', state.summaryText.indexOf(fx.storeName) !== -1,
            state.summaryText);

        // ---------------------------------------------------------------- Escape
        await page.click('[data-practice-store-summary]');
        await page.keyboard.press('Escape');
        const afterEscape = await page.evaluate(() => ({
            panelOpen: !document.querySelector('[data-practice-store-panel]').hidden,
            dialogOpen: document.querySelector('#practice-topic-dialog').open,
        }));
        check('Escape closes the list', afterEscape.panelOpen === false);
        check('…and leaves the dialog open', afterEscape.dialogOpen === true);

        // ---------------------------------------------------------------- saving, then Edit
        await page.type('#practice-topic-name', fx.newName || 'ZZ E2E Store Scoped');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#practice-topic-dialog form button[type="submit"]'),
        ]);

        const saved = await page.evaluate((name) => {
            const row = Array.from(document.querySelectorAll('tbody tr'))
                .find((tr) => tr.textContent.indexOf(name) !== -1);

            return row === undefined ? null : row.textContent.replace(/\s+/g, ' ').trim();
        }, fx.newName || 'ZZ E2E Store Scoped');

        check('the new topic is listed with its store', saved !== null && saved.indexOf(fx.storeName) !== -1,
            saved === null ? 'row not found' : saved.slice(0, 120));

        // The preselection check, which is the one that catches a picker built from the wrong source.
        const editHref = await page.evaluate((name) => {
            const row = Array.from(document.querySelectorAll('tbody tr'))
                .find((tr) => tr.textContent.indexOf(name) !== -1);

            return row === undefined ? null : row.querySelector('a[href*="topic="]')?.getAttribute('href') ?? null;
        }, fx.newName || 'ZZ E2E Store Scoped');

        if (editHref === null) {
            check('an Edit link exists for the new topic', false);
        } else {
            await page.goto(BASE + editHref, { waitUntil: 'networkidle0' });
            state = await picker(page);
            check('Edit preselects the stored store', state.selectValue === String(fx.store),
                'value=' + state.selectValue);
            check('…and the button reads it back', state.summaryText.indexOf(fx.storeName) !== -1,
                state.summaryText);
        }

        // ---------------------------------------------------------------- the policy
        const violations = await page.evaluate(() => window.__csp || []);

        check('no CSP violation anywhere in the flow', violations.length === 0,
            violations.join(' | ') || 'none');
        check('no page errors', errors.length === 0, errors.join(' | ') || 'none');
    } catch (e) {
        check('the run completed', false, e.message);
    } finally {
        await browser.close();
    }

    console.log(failures === 0 ? '\nstore-picker: OK' : '\nstore-picker: ' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
