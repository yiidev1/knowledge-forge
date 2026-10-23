/*
 * The Manage Orders table, measured in a real browser.
 *
 * ## Why this exists
 *
 * Every column-width regression in this table has been invisible to markup tests. A `<colgroup>` one
 * `col` short does not leave the last column unsized — it shifts every width one place to the left.
 * A cell with a `min-width` the column cannot honour does not wrap, it widens the table and the
 * wrapper scrolls. Neither shows up in `seeElement`; both are obvious the moment something measures
 * `scrollWidth` against `clientWidth`.
 *
 * So this asks the browser the questions a person asks looking at the page: does it scroll
 * sideways, are the last columns reachable, do the controls line up, and does it read in both
 * themes.
 *
 *   php tests/E2E/seed-practice.php > fixtures.json
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/manage-orders-layout.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { BASE, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const OUT = process.env.KF_SHOTS || path.join(__dirname, '_shots');

/* The three desktop widths named in the brief, plus the two that decide the responsive fallback. */
const VIEWPORTS = [
    { name: '1920', width: 1920, height: 1080, desktop: true },
    { name: '1440', width: 1440, height: 900, desktop: true },
    { name: '1280', width: 1280, height: 800, desktop: true },
    { name: 'tablet-820', width: 820, height: 1180, desktop: false },
    { name: 'mobile-390', width: 390, height: 844, desktop: false },
];

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function measure(page) {
    return page.evaluate(() => {
        const wrap = document.querySelector('.a2t-table-scroll');
        const table = document.querySelector('.a2t-orders.ot-orders');
        const doc = document.documentElement;

        if (!table) {
            return { missing: true };
        }

        const headers = Array.from(table.querySelectorAll('thead th')).map((th) => ({
            text: th.textContent.trim(),
            width: Math.round(th.getBoundingClientRect().width),
        }));

        const firstRow = table.querySelector('tbody tr');
        const lastCell = firstRow ? firstRow.lastElementChild : null;

        return {
            // The wrapper scrolls when its content is wider than itself. This is the number the
            // scrollbar in the screenshot actually represents.
            wrapScroll: wrap ? wrap.scrollWidth - wrap.clientWidth : null,
            // And the page itself must never scroll sideways.
            pageScroll: doc.scrollWidth - doc.clientWidth,
            tableWidth: Math.round(table.getBoundingClientRect().width),
            wrapWidth: wrap ? Math.round(wrap.clientWidth) : null,
            colCount: table.querySelectorAll('colgroup col').length,
            headerCount: headers.length,
            headers,
            // Is the rightmost cell actually inside the viewport, or painted past its edge?
            lastCellRight: lastCell ? Math.round(lastCell.getBoundingClientRect().right) : null,
            viewportRight: doc.clientWidth,
            rowHeights: Array.from(table.querySelectorAll('tbody tr')).map(
                (tr) => Math.round(tr.getBoundingClientRect().height),
            ),
        };
    });
}

async function run() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1440, height: 900 },
    });
    const page = await browser.newPage();

    const errors = [];
    const csp = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
    });
    /*
     * Violations collected on the Node side, not in `window`.
     *
     * A page-side array resets on every navigation, so reading it at the end only ever reported the
     * last page — which is how a real violation on an earlier load passed as "none". `exposeFunction`
     * survives navigation, so every violation lands in one list with the page it came from.
     */
    const cspHits = [];
    /*
     * Attributed to the page it happened on.
     *
     * Signing in lands on the dashboard, which carries a pre-existing inline `style` attribute of its
     * own — so a bare count reported that page's violation as this one's. Only hits whose source is
     * the table under test are this test's business.
     */
    await page.exposeFunction('__kfCsp', (hit) => cspHits.push(hit));
    await page.evaluateOnNewDocument(() => {
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__kfCsp(
                e.violatedDirective + ' @ ' + (e.sourceFile || 'inline') + ':' + e.lineNumber,
            );
        });
    });

    fs.mkdirSync(OUT, { recursive: true });

    await signIn(page, fx);

    const url = BASE + '/order-testing/store/' + fx.store;

    for (const theme of ['dark', 'light']) {
        await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }]);

        for (const vp of VIEWPORTS) {
            await page.setViewport({ width: vp.width, height: vp.height });
            await page.goto(url, { waitUntil: 'networkidle0' });

            const m = await measure(page);
            const tag = theme + '/' + vp.name;

            if (m.missing) {
                check(tag + ' table present', false, 'no .a2t-orders.ot-orders');
                continue;
            }

            // The colgroup and the header row must describe the same number of columns. This is the
            // check that catches a shifted `<col>` before anyone sees a squashed column.
            check(tag + ' colgroup matches headers', m.colCount === m.headerCount,
                m.colCount + ' cols vs ' + m.headerCount + ' headers');

            if (vp.desktop) {
                // The headline requirement: no sideways scrolling on a desktop.
                check(tag + ' no horizontal scroll', m.wrapScroll === 0,
                    'overflow ' + m.wrapScroll + 'px (table ' + m.tableWidth + ' in ' + m.wrapWidth + ')');
                // And the last column reachable rather than painted past the edge.
                check(tag + ' last column within viewport', m.lastCellRight <= m.viewportRight,
                    'right edge ' + m.lastCellRight + ' vs viewport ' + m.viewportRight);
            }

            check(tag + ' page does not scroll sideways', m.pageScroll <= 0, m.pageScroll + 'px');

            await page.screenshot({
                path: path.join(OUT, 'manage-orders-' + theme + '-' + vp.name + '.png'),
                fullPage: false,
            });

            if (vp.name === '1440' && theme === 'dark') {
                console.log('        columns: ' + m.headers.map((h) => h.text + ' ' + h.width).join(' | '));
                console.log('        row heights: ' + m.rowHeights.join(', '));
            }
        }
    }

    // ---- the topic editor, opened the way a person opens it -------------------------------------
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(url, { waitUntil: 'networkidle0' });

    const gear = await page.$('.ot-topic__picker summary');
    check('topic gear present', gear !== null);

    if (gear !== null) {
        const before = await page.$$eval('.ot-topic__panel', (n) => n.filter((p) => p.offsetParent !== null).length);
        check('editor closed at rest', before === 0, before + ' visible');

        await gear.click();
        await new Promise((r) => setTimeout(r, 150));

        const open = await page.$$eval('.ot-topic__panel', (n) => n.filter((p) => p.offsetParent !== null).length);
        check('gear opens the editor', open === 1, open + ' visible');

        const fits = await page.evaluate(() => {
            const panel = document.querySelector('.ot-topic__picker[open] .ot-topic__panel');
            if (!panel) return null;
            const r = panel.getBoundingClientRect();
            return r.left >= 0 && r.right <= document.documentElement.clientWidth;
        });
        check('editor stays on screen', fits === true);

        // Escape closes it and hands focus back, which is the keyboard contract.
        await page.keyboard.press('Escape');
        await new Promise((r) => setTimeout(r, 150));
        const afterEsc = await page.$$eval('.ot-topic__panel', (n) => n.filter((p) => p.offsetParent !== null).length);
        check('Escape closes the editor', afterEsc === 0, afterEsc + ' visible');
    }

    // ---- and it saves, which is the only thing that matters about an editor ----------------------
    await page.goto(url, { waitUntil: 'networkidle0' });

    const choice = await page.evaluate(() => {
        const radio = document.querySelector('.ot-topic__panel input[type="radio"][value]:not([value=""])');
        return radio ? { value: radio.value, label: radio.closest('label').textContent.trim() } : null;
    });

    if (choice === null) {
        console.log('  SKIP  topic save — the fixture store has no assignable topics');
    } else {
        await page.click('.ot-topic__picker summary');
        await new Promise((r) => setTimeout(r, 120));
        await page.click('.ot-topic__panel input[value="' + choice.value + '"]');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.ot-topic__save'),
        ]);

        const chip = await page.$eval('.ot-topic .practice-chip', (el) => el.textContent.trim());
        check('saving sets the chip', chip === choice.label, 'chip reads "' + chip + '", chose "' + choice.label + '"');

        // Reloaded from the server, so this is persistence and not a hopeful repaint.
        await page.reload({ waitUntil: 'networkidle0' });
        const after = await page.$eval('.ot-topic .practice-chip', (el) => el.textContent.trim());
        check('the topic survives a reload', after === choice.label, 'chip reads "' + after + '"');

        const closed = await page.$$eval('.ot-topic__panel', (n) => n.filter((x) => x.offsetParent !== null).length);
        check('the editor closes after saving', closed === 0, closed + ' visible');
    }

    const mine = cspHits.filter((h) => h.indexOf('/order-testing/') !== -1);
    const elsewhere = cspHits.filter((h) => h.indexOf('/order-testing/') === -1);

    check('no CSP violations on Manage Orders', mine.length === 0, mine.slice(0, 4).join(' ; '));

    if (elsewhere.length > 0) {
        // Reported, not failed: these belong to pages this test only passes through.
        console.log('  NOTE  pre-existing CSP violations elsewhere: ' + elsewhere.slice(0, 3).join(' ; '));
    }
    const realErrors = errors.filter((e) => e.indexOf('Content Security Policy') === -1);
    check('no page errors', realErrors.length === 0, realErrors.slice(0, 3).join(' | '));

    await browser.close();
    console.log('\nScreenshots in ' + OUT);
    console.log(failures === 0 ? 'ALL PASS' : failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
