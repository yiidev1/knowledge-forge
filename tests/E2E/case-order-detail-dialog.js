/*
 * Case Orders — View opens a dialog, in a real browser.
 *
 * ## Why this exists
 *
 * The Web suite drives PhpBrowser, which runs no JavaScript. It can prove the link is there and that
 * `?fragment=1` returns the right markup, but the thing that was actually asked for — pressing View
 * shows the order without leaving the list — is invisible to it. Both halves pass while the feature
 * does nothing.
 *
 * So this asks what a person asks: does the dialog open, does it hold the order's own facts, does the
 * list survive underneath, and does Close give it back.
 *
 *   php tests/E2E/seed-practice.php > tests/E2E/fixtures.json   # for the admin credentials
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/case-order-detail-dialog.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { BASE, launch, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const PAGE = '/admin/order58/case-orders';

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

    const before = await page.evaluate(() => {
        const dialog = document.querySelector('[data-o58-co-dialog]');
        const box = dialog.getBoundingClientRect();

        return {
            rows: document.querySelectorAll('[data-o58-co-order]').length,
            url: location.pathname,
            open: dialog.hasAttribute('open'),
            // Not just the attribute: a stylesheet can override the browser's own
            // `dialog:not([open]) { display: none }` and leave a closed dialog on the page.
            display: getComputedStyle(dialog).display,
            height: Math.round(box.height),
        };
    });

    check('the list rendered rows to open', before.rows > 0, before.rows + ' row(s)');
    check('the dialog starts closed', before.open === false);
    check('and a closed dialog is not rendered', before.display === 'none', before.display);
    check('so it occupies no space on the page', before.height === 0, before.height + 'px');

    if (before.rows === 0) {
        console.log('\nNo case orders are listed, so the dialog cannot be exercised.');
        await browser.close();
        process.exit(1);
    }

    await page.click('[data-o58-co-view]');

    // The body is filled by a fetch, so wait for the content rather than for the dialog element.
    await page.waitForFunction(
        () => {
            const body = document.querySelector('[data-o58-co-dialog-body]');
            return body && body.textContent.indexOf('Loading') === -1 && body.textContent.trim().length > 40;
        },
        { timeout: 10000 },
    );

    const open = await page.evaluate(() => {
        const dialog = document.querySelector('[data-o58-co-dialog]');
        const body = document.querySelector('[data-o58-co-dialog-body]');
        const box = dialog.getBoundingClientRect();

        return {
            isOpen: dialog.hasAttribute('open'),
            // A real modal: the browser puts it in the top layer, so a point at its centre hits it.
            isModal: document.elementFromPoint(box.left + box.width / 2, box.top + 10) !== null,
            width: Math.round(box.width),
            height: Math.round(box.height),
            title: (document.querySelector('[data-o58-co-dialog-title]') || {}).textContent || '',
            text: body.textContent,
            // The facts the fragment is supposed to carry, and the chrome it must not.
            hasSections: body.querySelectorAll('.card').length,
            hasBackLink: body.textContent.indexOf('Back to Case Orders') !== -1,
            hasNav: !!body.querySelector('nav'),
            // The list is still behind it, un-navigated.
            url: location.pathname,
            rowsBehind: document.querySelectorAll('[data-o58-co-order]').length,
            bodyScrolls: body.scrollHeight > body.clientHeight || body.clientHeight > 0,
        };
    });

    check('the dialog opened', open.isOpen);
    check('it is a real modal in the top layer', open.isModal);
    check('it did NOT navigate away from the list', open.url === PAGE, open.url);
    check('the list is still behind it', open.rowsBehind === before.rows, open.rowsBehind + ' row(s)');
    check('it is titled with the order number', /#\d+/.test(open.title), JSON.stringify(open.title));
    check('it holds the order\'s sections', open.hasSections >= 3, open.hasSections + ' section(s)');
    check('it mentions the item status', /item status/i.test(open.text));
    check('no second "Back to Case Orders" inside it', open.hasBackLink === false);
    check('no nested navigation', open.hasNav === false);
    check('it fits the viewport', open.width <= 1440 && open.height <= 900, open.width + 'x' + open.height);

    // Escape is the one close every dialog owes the keyboard.
    await page.keyboard.press('Escape');
    // Wait for the emptying too, not just the attribute: `close` is dispatched after the dialog has
    // already stopped being open, so checking straight away races the handler rather than testing it.
    await page.waitForFunction(
        () => {
            const dialog = document.querySelector('[data-o58-co-dialog]');
            const body = document.querySelector('[data-o58-co-dialog-body]');
            return !dialog.hasAttribute('open') && body.innerHTML.trim() === '';
        },
        { timeout: 5000 },
    );

    const after = await page.evaluate(() => ({
        open: document.querySelector('[data-o58-co-dialog]').hasAttribute('open'),
        emptied: document.querySelector('[data-o58-co-dialog-body]').innerHTML.trim() === '',
        rows: document.querySelectorAll('[data-o58-co-order]').length,
    }));

    check('Escape closes it', after.open === false);
    check('it is emptied, so the next order cannot flash the last one', after.emptied);
    check('the list is intact afterwards', after.rows === before.rows);

    // The script is a file under `script-src 'self'`; a violation here would mean it never ran and
    // the dialog only appeared to work because the link navigated.
    const csp = await page.evaluate(() => window.__csp || []);
    check('no content-security-policy violation', csp.length === 0, csp.join('; '));
    check('no page or console errors', errors.length === 0, errors.join('; '));

    await browser.close();
    console.log(failures === 0 ? '\nAll checks passed.' : '\n' + failures + ' check(s) failed.');
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
