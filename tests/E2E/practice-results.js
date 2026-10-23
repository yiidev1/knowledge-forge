/*
 * Practice Results in a real browser: the table, the drawer, both themes, and the keyboard.
 *
 * ## What only a browser can answer here
 *
 * Whether the drawer actually opens and actually closes; whether Escape reaches it; whether focus
 * comes back to the row the reader was on; whether the table behind it keeps its search and its
 * page. Every one of those is invisible to a markup test, which sees the same HTML whether the
 * panel is painted or not — the mistake this suite exists to catch, and one I have already made
 * once on this page.
 *
 *   php tests/E2E/seed-practice.php > fixtures.json
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/practice-results.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { BASE, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const OUT = process.env.KF_SHOTS || path.join(__dirname, '_shots');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function run() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1440, height: 900 },
    });
    const page = await browser.newPage();

    const errors = [];
    const cspHits = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
    });
    await page.exposeFunction('__kfCsp', (hit) => cspHits.push(hit));
    await page.evaluateOnNewDocument(() => {
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__kfCsp(e.violatedDirective + ' @ ' + (e.sourceFile || 'inline') + ':' + e.lineNumber);
        });
    });

    fs.mkdirSync(OUT, { recursive: true });
    await signIn(page, fx);

    const url = BASE + fx.resultsUrl;

    // ---- the table, in both themes and at three widths ------------------------------------------
    for (const theme of ['dark', 'light']) {
        await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }]);

        for (const vp of [
            { name: '1440', width: 1440, height: 900, desktop: true },
            { name: '1280', width: 1280, height: 800, desktop: true },
            { name: 'mobile-390', width: 390, height: 844, desktop: false },
        ]) {
            await page.setViewport({ width: vp.width, height: vp.height });
            await page.goto(url, { waitUntil: 'networkidle0' });

            const m = await page.evaluate(() => {
                const doc = document.documentElement;
                const wrap = document.querySelector('.a2t-table-scroll');

                return {
                    // The practitioner table only — the Unmatched table below it is its own.
                    rows: document.querySelectorAll('.pr-table:not(.pr-table--unmatched) tbody tr').length,
                    pageScroll: doc.scrollWidth - doc.clientWidth,
                    wrapScroll: wrap ? wrap.scrollWidth - wrap.clientWidth : 0,
                    // A zero printed where no comparison exists would be the worst bug on this page.
                    // A cell styled as having no score must not also print one.
                    unavailableWithNumber: Array.from(
                        document.querySelectorAll('.pr-score--none, .pr-score--unknown'),
                    ).filter((s) => /\d/.test(s.textContent)).length,
                    drawerVisible: document.querySelector('[data-pr-drawer]:not([hidden])') !== null,
                };
            });

            const tag = theme + '/' + vp.name;
            check(tag + ' page does not scroll sideways', m.pageScroll <= 0, m.pageScroll + 'px');

            if (vp.desktop) {
                check(tag + ' table does not overflow', m.wrapScroll === 0, m.wrapScroll + 'px');
                check(tag + ' pages at 25 practitioners', m.rows === 25, m.rows + ' rows');
            }

            check(tag + ' drawer is closed at rest', m.drawerVisible === false);

            /*
             * Every `0%` on the page has to be a score somebody earned.
             *
             * This fixture's demos genuinely score zero, so zeroes are expected here — what is
             * asserted is that none of them sits in a cell whose comparison was unavailable, which
             * would be a dash. Checked by there being no cell that is both.
             */
            check(tag + ' no unavailable score is printed as a number',
                m.unavailableWithNumber === 0, m.unavailableWithNumber + ' cells');

            await page.screenshot({
                path: path.join(OUT, 'practice-results-' + theme + '-' + vp.name + '.png'),
            });
        }
    }

    // ---- the drawer -----------------------------------------------------------------------------
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(url, { waitUntil: 'networkidle0' });

    const requests = [];
    page.on('request', (r) => {
        if (r.url().includes('/demo-orders/tries/')) requests.push(r.url());
    });

    await page.click('.pr-table tbody tr:nth-child(2) [data-pr-tries]');
    await wait(600);

    const opened = await page.evaluate(() => {
        const drawer = document.querySelector('[data-pr-drawer]');

        return {
            visible: drawer !== null && !drawer.hidden,
            title: (document.querySelector('[data-pr-drawer-title]') || {}).textContent,
            tries: document.querySelectorAll('.pr-drawer__body .pr-table--tries tbody tr').length,
            focused: document.activeElement
                ? document.activeElement.getAttribute('data-pr-drawer-panel') !== null
                : false,
        };
    });

    check('the drawer opens', opened.visible === true);
    check('it names the practitioner', (opened.title || '').length > 0, opened.title);
    check('it lists that practitioner\'s tries', opened.tries > 0, opened.tries + ' rows');
    check('focus moves into the panel', opened.focused === true);

    // Exactly one request, for exactly one practitioner — the table itself fetched no histories.
    check('one request, for one practitioner', requests.length === 1, requests.length + ' requests');

    await page.screenshot({ path: path.join(OUT, 'practice-results-drawer-dark.png') });

    await page.keyboard.press('Escape');
    await wait(250);

    const closed = await page.evaluate(() => {
        const drawer = document.querySelector('[data-pr-drawer]');

        return {
            hidden: drawer === null || drawer.hidden,
            // Focus back on the control that opened it, or the reader loses their place.
            backOnTrigger: document.activeElement !== null
                && document.activeElement.hasAttribute('data-pr-tries'),
        };
    });

    check('Escape closes the drawer', closed.hidden === true);
    check('focus returns to the row', closed.backOnTrigger === true);

    // ---- search and paging survive each other ---------------------------------------------------
    await page.goto(url + '?agent=e2e_agent_1&sort=name', { waitUntil: 'networkidle0' });

    const searched = await page.evaluate(() => ({
        rows: document.querySelectorAll('.pr-table:not(.pr-table--unmatched) tbody tr').length,
        names: Array.from(document.querySelectorAll('.pr-agent')).map((n) => n.textContent.trim()),
        pagerKeepsFilter: Array.from(document.querySelectorAll('.pr-pager a'))
            .every((a) => a.getAttribute('href').includes('agent=e2e_agent_1')),
    }));

    check('search narrows the table', searched.rows > 0 && searched.rows < 25, searched.rows + ' rows');
    check('every row matches the search',
        searched.names.every((n) => n.indexOf('e2e_agent_1') === 0),
        searched.names.slice(0, 3).join(', '));
    check('the pager keeps the search', searched.pagerKeepsFilter === true);

    // ---- a long history is reported honestly, never as a guess ----------------------------------
    await page.goto(url + '?agent=e2e_agent_00', { waitUntil: 'networkidle0' });

    const longHistory = await page.evaluate(() => {
        const row = document.querySelector('.pr-table:not(.pr-table--unmatched) tbody tr');

        return {
            best: row ? row.querySelectorAll('.pr-score')[0].textContent.trim() : null,
            latest: row ? row.querySelectorAll('.pr-score')[1].textContent.trim() : null,
        };
    });

    check('an unscoreable Best says so rather than guessing',
        longHistory.best === 'Not available', longHistory.best);
    /*
     * Latest is reported, whatever it is.
     *
     * `0%` is a legitimate score here and not a bug: this fixture's source order totals 10.00 and
     * its demo orders 12.50, so every compared field differs and the comparison honestly scores
     * nothing. What must never appear is a score for a comparison that could not be MADE — that
     * renders as a dash, and `PractitionerScorerTest` pins it where the condition can be created.
     */
    check('Latest is reported even for a long history',
        longHistory.latest !== null && longHistory.latest !== '—'
            && longHistory.latest !== 'Not available',
        longHistory.latest);

    const mine = cspHits.filter((h) => h.indexOf('/order-testing/') !== -1);
    check('no CSP violations on Practice Results', mine.length === 0, mine.slice(0, 3).join(' ; '));

    const realErrors = errors.filter((e) => e.indexOf('Content Security Policy') === -1);
    check('no page errors', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));

    await browser.close();
    console.log('\nScreenshots in ' + OUT);
    console.log(failures === 0 ? 'ALL PASS' : failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
