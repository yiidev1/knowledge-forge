/*
 * The two store listings, measured: column proportions, and the transcript that moved into the dialog.
 *
 * Both pages render one row per order from the same domain objects, and their tables carried the same
 * `a2t-` classes and the same fixed column widths — widths chosen when there were seven columns and the
 * conversation column took whatever was left. Removing Text to Audio and Actions handed all of that
 * slack to that one column, so it grew to about half the table while the short columns stayed at their
 * old sizes and bunched against the right edge.
 *
 * Percentages fixed it, and percentages are exactly the thing a markup test cannot check: PhpBrowser
 * neither lays out nor measures. So this reads the real geometry out of Chrome at the widths the work
 * was asked to cover, and checks the two pages separately, because they now want different proportions.
 *
 * It also presses Original transcript in each page's dialog. That control is the only way in since the
 * Actions column went, and on the audio page its own Cest cannot reach it — that suite's upload fixture
 * throws on HEAD, so this is where the audio listing actually gets verified.
 */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures.json');

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const PAGES = [
    { name: 'ORDER TESTING', path: '/order-testing/store/', columns: 5,
      headers: ['Order No#', 'Recording / Conversation', 'Status', 'Demo URL', 'Demo Orders'] },
    { name: 'AUDIO TO TEXT', path: '/audio-to-text/store/', columns: 4,
      headers: ['Order No#', 'Recording / Conversation', 'Status', 'Demo URL'] },
];

/** Header text and the share of the table each column actually occupies, as rendered. */
function readTable(page) {
    return page.evaluate(() => {
        const table = document.querySelector('.a2t-orders');

        if (table === null) {
            return null;
        }

        const width = table.getBoundingClientRect().width;
        const head = [...table.querySelectorAll('thead th')];

        return {
            width: Math.round(width),
            headers: head.map((h) => h.textContent.trim()),
            shares: head.map((h) => Math.round((h.getBoundingClientRect().width / width) * 1000) / 10),
            cells: [...new Set([...table.querySelectorAll('tbody tr')]
                .map((r) => r.querySelectorAll(':scope > td').length))],
            // A cell wider than its column is text escaping the table rather than wrapping inside it.
            overflow: [...table.querySelectorAll('tbody td')]
                .filter((td) => td.scrollWidth > td.clientWidth + 1).length,
            pageScroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    });
}

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    for (const spec of PAGES) {
        for (const width of [1600, 1440, 1200, 768, 390]) {
            await page.setViewport({ width, height: 1000 });
            await page.goto(BASE + spec.path + fx.store, { waitUntil: 'networkidle0' });
            await page.evaluate(() => document.fonts.ready);

            const t = await readTable(page);
            const at = spec.name + ' @' + width + 'px';

            if (t === null) {
                check(at + ': table rendered', false);
                continue;
            }

            check(at + ': headers', JSON.stringify(t.headers) === JSON.stringify(spec.headers),
                JSON.stringify(t.headers));
            check(at + ': every row has ' + spec.columns + ' cells',
                t.cells.length === 1 && t.cells[0] === spec.columns, JSON.stringify(t.cells));
            check(at + ': nothing clipped', t.overflow === 0, t.overflow + ' cells overflow');
            check(at + ': no horizontal page scroll', t.pageScroll <= 0, String(t.pageScroll));

            // The point of the change: no column may run away with the table. Checked only where the
            // table is not being scrolled — below its own minimum the columns fall back to content.
            if (width >= 1200) {
                const conversation = t.shares[1];
                check(at + ': conversation column is in proportion',
                    conversation >= 25 && conversation <= 45, conversation + '%');
                check(at + ': no column is starved',
                    t.shares.every((s) => s >= 8), JSON.stringify(t.shares));
                check(at + ': shares add up', Math.abs(t.shares.reduce((a, b) => a + b, 0) - 100) < 2,
                    t.shares.reduce((a, b) => a + b, 0) + '%');
            }
        }

        // ---- and the transcript control, on this page's own dialog --------------------------------
        await page.setViewport({ width: 1500, height: 1000 });
        await page.goto(BASE + spec.path + fx.store, { waitUntil: 'networkidle0' });

        const opener = await page.$('[data-a2t-order-open]');
        check(spec.name + ': View conversation is offered', opener !== null);

        if (opener === null) {
            continue;
        }

        await opener.click();
        await page.waitForSelector('#a2t-review-dialog[open]', { timeout: 5000 });
        await sleep(400);

        await page.click('[data-a2t-review-transcripts]');
        await page.waitForSelector('#a2t-transcript-dialog[open]', { timeout: 5000 });
        await sleep(600);

        const text = await page.$eval('#a2t-transcript-dialog', (d) => d.textContent);
        check(spec.name + ': Original transcript opens with real text',
            text.includes('pepperoni') || text.includes('delivery'),
            text.replace(/\s+/g, ' ').slice(0, 70));

        await page.keyboard.press('Escape');
        await sleep(250);
        check(spec.name + ': the conversation is still open underneath',
            await page.$eval('#a2t-review-dialog', (d) => d.open));
        await page.keyboard.press('Escape');
        await sleep(200);
    }

    const csp = await page.evaluate(() => window.__csp || []);
    check('no CSP violation', csp.length === 0, csp.join(' | '));
    // The seed writes rows, not bytes, so the dialog's player asks for a file that was never created.
    const real = errors.filter((e) => !/404/.test(e));
    check('no console or page errors', real.length === 0, real.join(' | '));

    await browser.close();
    console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
