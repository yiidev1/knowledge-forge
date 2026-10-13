/*
 * The two invoices on the Order Comparison page, measured rather than asserted about.
 *
 * Markup tests can prove both panels emit the same rows in the same order. They cannot prove those rows
 * end up at the same height, because that is decided by text wrapping, and PhpBrowser neither lays out
 * nor wraps anything. The whole defect this guards lives in that gap: an "Options" value that wraps onto
 * a second line on one side used to push every row below it out of step with its opposite number, and
 * by the Totals the two invoices disagreed about which line was which.
 *
 * So this signs in, opens one deliberately lopsided comparison, and reads real geometry out of a real
 * Chrome at four widths.
 */
const { BASE, launch } = require('./lib');
const fx = require('./fixtures-compare.json');

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** Sub-pixel rounding is not a misalignment; half a line of text is. */
const TOLERANCE = 1.5;

/**
 * Every row of one panel, in the order it is drawn, with its top edge and height.
 *
 * The selector lists exactly the elements that occupy one row of the shared grid. It is deliberately
 * explicit rather than "every child": a wrapper that still had a box would be invisible here, which is
 * the one thing that would quietly break the pairing.
 */
const ROWS = [
    '.ot-book__head',
    '.ot-book__section-title',
    '.ot-book__line',
    '.ot-book__item-head',
    '.ot-book__removed',
    '.ot-book__section--items > p',
].join(',');

function readPanels(page) {
    return page.evaluate((rows) => {
        const read = (side) => {
            const panel = document.querySelector('.ot-book--' + side);
            const box = panel.getBoundingClientRect();
            return {
                top: box.top + window.scrollY,
                bottom: box.bottom + window.scrollY,
                left: box.left,
                rows: [...panel.querySelectorAll(rows)].map((el) => {
                    const r = el.getBoundingClientRect();
                    return {
                        top: Math.round((r.top + window.scrollY) * 100) / 100,
                        height: Math.round(r.height * 100) / 100,
                        label: (el.querySelector('.ot-book__term, .ot-book__item-name') || el).textContent
                            .trim().replace(/\s+/g, ' ').slice(0, 40),
                    };
                }),
            };
        };
        return {
            original: read('original'),
            demo: read('demo'),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }, ROWS);
}

async function at(page, width, label) {
    await page.setViewport({ width, height: 1200 });
    await page.goto(BASE + fx.url, { waitUntil: 'networkidle0' });
    // The font the rows are measured in must be the font they will be read in.
    await page.evaluate(() => document.fonts.ready);
    console.log('\n' + label + ' — ' + width + 'px');
    return readPanels(page);
}

(async () => {
    const { browser, page, errors } = await launch();

    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('input[name="username"]', fx.admin);
    await page.type('input[name="password"]', fx.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('form button[type="submit"], form input[type="submit"]'),
    ]);

    // ---- side by side: every row must face its opposite number ------------------------------------
    for (const [width, label] of [[1600, 'WIDE DESKTOP'], [1440, 'DESKTOP'], [1200, 'MEDIUM'], [1160, 'NEAR BREAKPOINT']]) {
        const { original, demo, overflow } = await at(page, width, label);

        check('two columns, demo to the right of original', demo.left > original.left,
            original.left + ' / ' + demo.left);
        check('same number of rows on both sides', original.rows.length === demo.rows.length,
            original.rows.length + ' vs ' + demo.rows.length);

        if (original.rows.length !== demo.rows.length) {
            continue;
        }

        const drift = original.rows
            .map((row, i) => ({ i, label: row.label, delta: Math.abs(row.top - demo.rows[i].top) }))
            .filter((r) => r.delta > TOLERANCE);

        check('every row starts at the same height', drift.length === 0,
            drift.length === 0
                ? original.rows.length + ' rows aligned'
                : drift.slice(0, 4).map((d) => d.label + ' off by ' + d.delta.toFixed(1) + 'px').join('; '));

        // A row that is taller on one side must be exactly as tall on the other, or the next one drifts.
        const heights = original.rows
            .map((row, i) => ({ label: row.label, a: row.height, b: demo.rows[i].height }))
            .filter((r) => Math.abs(r.a - r.b) > TOLERANCE);
        check('matching rows are the same height', heights.length === 0,
            heights.slice(0, 4).map((h) => h.label + ' ' + h.a + ' vs ' + h.b).join('; '));

        // The point of the exercise: the taller content decides, and it is not always the same side.
        const wrapped = original.rows
            .map((row, i) => ({ label: row.label, a: row.height, b: demo.rows[i].height }))
            .filter((r) => r.a > 40);
        check('a wrapped row really is taller than a plain one', wrapped.length > 0,
            wrapped.slice(0, 3).map((w) => w.label + ' ' + w.a + 'px').join('; '));

        check('both panels end level', Math.abs(original.bottom - demo.bottom) <= TOLERANCE,
            original.bottom.toFixed(1) + ' vs ' + demo.bottom.toFixed(1));
        check('no horizontal page scroll', overflow <= 0, String(overflow));
    }

    // ---- stacked: no opposite row to match, so nothing is held ------------------------------------
    for (const [width, label] of [[1100, 'STACKED'], [768, 'TABLET'], [390, 'MOBILE']]) {
        const { original, demo, overflow } = await at(page, width, label);

        check('stacked, original first', demo.top >= original.bottom - TOLERANCE,
            'original ends ' + original.bottom.toFixed(0) + ', demo starts ' + demo.top.toFixed(0));
        check('no horizontal page scroll', overflow <= 0, String(overflow));

        // Heights are natural again: holding them here would only open blank space nobody can see past.
        const held = original.rows
            .map((row, i) => ({ label: row.label, a: row.height, b: demo.rows[i].height }))
            .filter((r) => r.a !== r.b);
        check('row heights are free to differ again', held.length > 0,
            held.length + ' of ' + original.rows.length + ' rows differ');
    }

    const csp = await page.evaluate(() => window.__csp || []);
    check('no CSP violation', csp.length === 0, csp.join(' | '));
    check('no console or page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
