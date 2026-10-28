/*
 * "Sync Order58 Case Orders" on Manage Orders, in a real browser — and the same control on Call Recordings.
 *
 * ## What only a browser can answer here
 *
 * The Web suite proves both pages render the same dialog and post to the same endpoint. What it cannot
 * see is the script: that the click opens a modal instead of navigating, that a press is sent once
 * however fast it is clicked twice, that the result is drawn in the dialog, and that closing it
 * reloads Manage Orders (whose store cards count case orders) while leaving Call Recordings alone.
 * And the header: one button more, on the same row, at the same height.
 *
 * ## No request reaches the provider
 *
 * The confirmation POST is intercepted in the browser and answered with a made-up finished run, so the
 * server never receives it. Every other request goes through untouched.
 *
 *   php tests/E2E/seed-practice.php > tests/E2E/fixtures.json   # any seed that writes an admin
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/manage-orders-case-sync.js
 *   php tests/E2E/cleanup-practice.php
 */
const fs = require('fs');
const path = require('path');
const { BASE, launch, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const OUT = process.env.KF_SHOTS || path.join(__dirname, '_shots');
const ENDPOINT = '/admin/order58/case-orders/sync';

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// A finished run, shaped as CaseOrderRunView::describe() writes one. Invented: the server never sees
// the request this answers.
const FAKE = {
    success: true,
    message: 'Case orders synchronised.',
    hasRun: true,
    run: {
        id: 987650001, businessDay: '2026-10-28', status: 'COMPLETED', statusLabel: 'Completed',
        modifier: 'success', finished: true, running: false, percent: 100, allowanceSpent: true,
        ordersReceived: 12, ordersInserted: 3, ordersUpdated: 2, ordersUnchanged: 7, ordersSkipped: 0,
        itemsImported: 41, failures: 0, message: null, canReimport: false,
    },
};

(async () => {
    const { browser, page, errors } = await launch();
    fs.mkdirSync(OUT, { recursive: true });

    // Every press is caught here and answered after a pause, so a second click lands mid-flight.
    let posts = 0;
    await page.setRequestInterception(true);
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().endsWith(ENDPOINT)) {
            posts += 1;
            setTimeout(() => request.respond({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(FAKE),
            }), 800);

            return;
        }

        request.continue();
    });

    await signIn(page, fx);

    // ---- Manage Orders: the header ----------------------------------------------------------------
    for (const width of [1440, 1280]) {
        await page.setViewport({ width, height: 900 });
        await page.goto(BASE + '/order-testing', { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);

        const header = await page.evaluate(() => {
            const actions = Array.from(document.querySelectorAll('.page-header__actions > *'))
                .filter((el) => el.getBoundingClientRect().height > 0);
            const buttons = Array.from(document.querySelectorAll('.page-header__actions .btn'))
                .map((el) => el.getBoundingClientRect());

            return {
                labels: actions.map((el) => el.textContent.trim()),
                classes: (document.querySelector('[data-o58-case-order-open]') || {}).className || '',
                tops: buttons.map((b) => Math.round(b.top)),
                heights: buttons.map((b) => Math.round(b.height)),
            };
        });

        check(width + ': the header has the three buttons, the new one first',
            header.labels.join(' | ') === 'Sync Order58 Case Orders | Sync demo orders | Transcription settings',
            header.labels.join(' | '));
        check(width + ': in the header\'s secondary style', /\bbtn--secondary\b/.test(header.classes), header.classes);
        check(width + ': on one row', new Set(header.tops).size === 1, header.tops.join(','));
        check(width + ': at one height',
            Math.max(...header.heights) - Math.min(...header.heights) <= 1, header.heights.join(','));

        await page.screenshot({ path: path.join(OUT, 'manage-orders-case-sync-header-' + width + '.png') });
    }

    // ---- Manage Orders: the dialog ----------------------------------------------------------------
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(BASE + '/order-testing?letter=B', { waitUntil: 'networkidle0' });

    await page.click('[data-o58-case-order-open]');
    await wait(300);

    const opened = await page.evaluate(() => ({
        open: document.querySelector('[data-o58-case-order-dialog]').open,
        url: location.pathname + location.search,
    }));

    check('the click opens the dialog', opened.open === true);
    check('without navigating', opened.url === '/order-testing?letter=B', opened.url);

    // Two quick presses: the second must not post again.
    await page.click('[data-o58-case-order-start]');
    await page.click('[data-o58-case-order-start]').catch(() => {});
    await page.waitForFunction(
        () => /Completed/.test(document.querySelector('[data-o58-case-order-state]').textContent),
        { timeout: 5000 },
    );

    const finished = await page.evaluate(() => ({
        state: document.querySelector('[data-o58-case-order-state]').textContent.trim(),
        inserted: document.querySelector('[data-o58-case-order-inserted]').textContent.trim(),
        items: document.querySelector('[data-o58-case-order-items]').textContent.trim(),
        button: document.querySelector('[data-o58-case-order-start]').textContent.trim(),
        disabled: document.querySelector('[data-o58-case-order-start]').disabled,
        panelHidden: document.querySelector('[data-o58-case-order-panel]').hidden,
    }));

    check('one press, one request', posts === 1, posts + ' POSTs');
    check('the result is drawn in the dialog', finished.state === 'Completed' && !finished.panelHidden, finished.state);
    check('with the counts the reply carried', finished.inserted === 'New: 3' && finished.items === 'Lines: 41',
        finished.inserted + ' / ' + finished.items);
    check('and the button can be pressed again', finished.button === 'Synchronise again' && !finished.disabled,
        finished.button);

    await page.screenshot({ path: path.join(OUT, 'manage-orders-case-sync-dialog.png') });

    // Closing after a finished run reloads the store cards, at the address Close names.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 10000 }),
        page.click('[data-o58-case-order-cancel]'),
    ]);

    const reloaded = await page.evaluate(() => ({
        url: location.pathname + location.search,
        open: document.querySelector('[data-o58-case-order-dialog]').open,
    }));

    check('closing reloads Manage Orders', reloaded.url === '/order-testing?letter=B', reloaded.url);
    check('with the dialog closed', reloaded.open === false);

    // Closing with nothing run reloads nothing.
    await page.click('[data-o58-case-order-open]');
    await wait(200);
    const before = await page.evaluate(() => performance.timeOrigin);
    await page.click('[data-o58-case-order-cancel]');
    await wait(800);
    const after = await page.evaluate(() => performance.timeOrigin);

    check('closing without a run does not reload', before === after);

    // ---- Call Recordings: the same control, as it was ---------------------------------------------
    await page.goto(BASE + '/admin/order58/call-recordings', { waitUntil: 'networkidle0' });

    const recordings = await page.evaluate(() => ({
        classes: (document.querySelector('[data-o58-case-order-open]') || {}).className || '',
        refresh: document.querySelector('[data-o58-case-order-dialog]').hasAttribute('data-o58-case-order-refresh'),
    }));

    check('Call Recordings keeps its primary button', /\bbtn--primary\b/.test(recordings.classes), recordings.classes);
    check('and does not reload on close', recordings.refresh === false);

    await page.click('[data-o58-case-order-open]');
    await wait(300);
    await page.click('[data-o58-case-order-start]');
    await page.waitForFunction(
        () => /Completed/.test(document.querySelector('[data-o58-case-order-state]').textContent),
        { timeout: 5000 },
    );

    const stay = await page.evaluate(() => performance.timeOrigin);
    await page.click('[data-o58-case-order-cancel]');
    await wait(800);

    check('Call Recordings: the same press, the same result', posts === 2, posts + ' POSTs');
    check('Call Recordings: closing leaves the page as it is',
        stay === await page.evaluate(() => performance.timeOrigin));

    check('no page or console errors', errors.length === 0, errors.join('; '));

    await browser.close();

    console.log(failures === 0 ? '\nAll checks passed.' : '\n' + failures + ' check(s) failed.');
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
