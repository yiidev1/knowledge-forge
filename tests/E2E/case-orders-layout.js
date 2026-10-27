/*
 * The Case Orders page, measured in a real browser.
 *
 * ## Why this exists
 *
 * The complaint was about layout, and layout is invisible to a markup test. `seeElement` passes just
 * as happily when nine filter controls are stacked one per line down the page as when they sit four
 * to a row — the elements are all there either way. What tells the two apart is asking the browser
 * how tall the form actually is, how many distinct rows its controls occupy, and whether the table
 * fits its box.
 *
 * So this asks what a person asks looking at the page: is the filter compact, is it laid out in rows
 * rather than a column, does the page scroll sideways, and is the last table column reachable.
 *
 *   php tests/E2E/seed-practice.php > fixtures.json   # for the admin credentials
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/case-orders-layout.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { BASE, launch, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const OUT = process.env.KF_SHOTS || path.join(__dirname, '_shots');
const PAGE = '/admin/order58/case-orders';

const VIEWPORTS = [
    { name: '1920', width: 1920, height: 1080, wide: true },
    { name: '1440', width: 1440, height: 900, wide: true },
    { name: '1280', width: 1280, height: 800, wide: true },
    { name: 'tablet-820', width: 820, height: 1180, wide: false },
    { name: 'mobile-390', width: 390, height: 844, wide: false },
];

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function measure(page) {
    return page.evaluate(() => {
        const form = document.querySelector('.o58-co-filters');
        const scroll = document.querySelector('.o58-table-scroll');
        const table = scroll ? scroll.querySelector('table') : null;

        /*
         * How many rows the controls occupy, measured by their BOTTOM edge.
         *
         * Counting rows rather than reading the computed columns, because what went wrong was
         * visual: every control claimed a full line. But tops are the wrong edge to count — the row
         * is `align-items: end`, so a two-input range group and a plain select in the SAME row start
         * at different heights and end at the same one. Counting tops reported three rows as six.
         */
        const cells = form ? Array.from(form.children) : [];
        const rows = new Set(cells.map((el) => Math.round(el.getBoundingClientRect().bottom)));

        const content = document.querySelector('.content');
        const sidebar = document.querySelector('.sidebar, nav.sidebar, .app > nav');

        return {
            // The whole point of the complaint: the column must use the screen, not a reading width.
            contentWidth: content ? Math.round(content.getBoundingClientRect().width) : null,
            viewportWidth: document.documentElement.clientWidth,
            // The content can only ever have what the sidebar leaves it, so that — not the raw
            // viewport — is what "uses the screen" has to be measured against.
            availableWidth: document.documentElement.clientWidth
                - (sidebar ? Math.round(sidebar.getBoundingClientRect().width) : 0),
            pageScrollsSideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            formHeight: form ? Math.round(form.getBoundingClientRect().height) : null,
            controlCount: cells.length,
            controlRows: rows.size,
            // A wide control that overflows its own track is how a grid still ends up ragged.
            anyControlOverflows: cells.some((el) => el.scrollWidth > el.clientWidth + 1),
            tableOverflowsItsBox: scroll && table
                ? table.scrollWidth > scroll.clientWidth + 1
                : null,
            lastHeader: table
                ? (table.querySelector('thead tr th:last-child') || {}).textContent || null
                : null,
        };
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    // `launch()` hands back the page already wired for console and CSP capture.
    const { browser, page } = await launch();

    await signIn(page, fx);

    for (const vp of VIEWPORTS) {
        await page.setViewport({ width: vp.width, height: vp.height });
        await page.goto(BASE + PAGE, { waitUntil: 'networkidle0' });

        const m = await measure(page);

        console.log('\n' + vp.name + ' (' + vp.width + 'px)');

        // The page itself must never scroll sideways — a wide table scrolls inside its own box.
        check('page does not scroll sideways', m.pageScrollsSideways === false);
        check('no filter control overflows its track', m.anyControlOverflows === false);

        if (vp.wide) {
            /*
             * Nine controls plus the actions row. One row per control is the bug that was reported;
             * four or fewer rows is the fix. The actions row is always one of them.
             */
            check(
                'filter uses rows, not a column',
                m.controlRows > 0 && m.controlRows <= 4,
                m.controlRows + ' row(s) for ' + m.controlCount + ' controls',
            );
            check('filter stays compact', m.formHeight !== null && m.formHeight < 260, m.formHeight + 'px');
        } else {
            // Narrow: stacking is correct here, and the only requirement is that nothing overflows.
            check('narrow layout stacks without overflow', m.anyControlOverflows === false);
        }

        check('last table column is present', typeof m.lastHeader === 'string', String(m.lastHeader).trim());

        if (vp.wide) {
            /*
             * The column opens to 1600px, so it takes what the sidebar leaves rather than stopping at
             * the 1240px reading width. Allowed 120px of gutter: `.content` has horizontal padding of
             * `clamp(1.25rem, 3vw, 2.5rem)` a side, which is space the column uses and does not waste.
             */
            const ceiling = Math.min(1600, m.availableWidth);
            check(
                'content column uses the width available beside the sidebar',
                m.contentWidth >= ceiling - 120,
                m.contentWidth + 'px of ' + m.availableWidth + ' available (' + m.viewportWidth + ' viewport)',
            );
        }

        await page.screenshot({ path: path.join(OUT, 'case-orders-' + vp.name + '.png'), fullPage: true });
    }

    await browser.close();

    console.log('\n' + (failures === 0 ? 'All layout checks passed.' : failures + ' check(s) failed.'));
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
