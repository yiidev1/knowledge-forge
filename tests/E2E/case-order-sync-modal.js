/*
 * The Sync confirmation modal, opened in a real browser with a finished run on record.
 *
 * ## Why this exists
 *
 * The reported bug was a state problem the Web suite cannot see: the dialog opened already showing
 * `Completed`, a full progress bar and a previous run's counts, before anything had been confirmed.
 * PhpBrowser can assert the panel carries `hidden`, which it now does — but `hidden` is only a hint,
 * and a stylesheet or a script can put the panel back on screen. What a person sees is whether those
 * figures are visible, and only a browser can answer that.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/case-order-sync-modal.js
 *
 * It opens the dialog and stops. It never presses Confirm, so no provider request is made.
 */
const fs = require('fs');
const path = require('path');
const { BASE, launch, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const PAGE = '/admin/order58/call-recordings';

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

(async () => {
    const { browser, page, errors } = await launch();

    await page.setViewport({ width: 1440, height: 900 });
    await signIn(page, fx);
    await page.goto(BASE + PAGE, { waitUntil: 'networkidle0' });

    const trigger = await page.$('[data-o58-case-order-open]');

    if (!trigger) {
        console.log('\nThe sync control is not offered on this host, so the dialog cannot be opened.');
        await browser.close();
        process.exit(1);
    }

    await page.click('[data-o58-case-order-open]');
    await page.waitForFunction(
        () => document.querySelector('[data-o58-case-order-dialog]').hasAttribute('open'),
        { timeout: 5000 },
    );

    const open = await page.evaluate(() => {
        const dialog = document.querySelector('[data-o58-case-order-dialog]');
        const panel = document.querySelector('[data-o58-case-order-panel]');
        const box = panel.getBoundingClientRect();

        return {
            dialogOpen: dialog.hasAttribute('open'),
            question: (dialog.querySelector('.a2t-confirm__title') || {}).textContent || '',
            hasConfirm: !!dialog.querySelector('[data-o58-case-order-start]'),
            // The whole of the bug: are a previous run's figures on screen before confirming?
            panelVisible: getComputedStyle(panel).display !== 'none' && box.height > 0,
            panelNamesARun: panel.hasAttribute('data-o58-case-order-run'),
            panelActive: panel.getAttribute('data-o58-case-order-active'),
            visibleText: box.height > 0 ? panel.textContent.replace(/\s+/g, ' ').trim() : '',
        };
    });

    check('the dialog opens', open.dialogOpen);
    check('it asks the question', /synchronise/i.test(open.question), JSON.stringify(open.question));
    check('it offers Confirm', open.hasConfirm);

    check('NO previous run is shown as progress', open.panelVisible === false, 'visible=' + open.panelVisible);
    check('the panel names no run', open.panelNamesARun === false);
    check('and it is not marked active', open.panelActive === '0', open.panelActive);
    check('no Completed badge on screen', !/completed/i.test(open.visibleText));
    check('no counts on screen', !/received:|lines:/i.test(open.visibleText));

    // Nothing was requested just by looking.
    const runsAfter = await page.evaluate(async (base) => {
        const r = await fetch(base + '/admin/order58/case-orders/status', {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        });

        return r.ok ? await r.json() : null;
    }, BASE);

    check('the status endpoint reports nothing running', runsAfter && runsAfter.hasRun === false,
        JSON.stringify(runsAfter && runsAfter.hasRun));

    check('no page or console errors', errors.length === 0, errors.join('; '));

    await browser.close();
    console.log(failures === 0 ? '\nAll checks passed.' : '\n' + failures + ' check(s) failed.');
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
