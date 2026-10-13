/*
 * Original transcript, reached from the View conversation dialog on the Order Testing listing.
 *
 * The listing used to carry an Actions column whose first button opened this. The column is gone and
 * the button moved into the dialog's header, which is the only reason an operation user still has a way
 * in at all — they are kept out of the audio module, where an administrator could always reach the same
 * thing. That makes "the button is in the markup" the wrong assertion: what matters is that pressing it
 * opens the dialog and that real transcript text arrives in it.
 *
 * Nothing here is visible to the Web suite. PhpBrowser runs no script, so it can see the control and
 * never learn whether it works.
 */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures.json');

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const visible = (page, sel) => page.$eval(sel, (el) => {
    const box = el.getBoundingClientRect();
    return !el.hidden && box.width > 0 && box.height > 0;
}).catch(() => false);

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    for (const [width, label] of [[1500, 'DESKTOP'], [390, 'MOBILE']]) {
        console.log('\n' + label + ' — ' + width + 'px');
        await page.setViewport({ width, height: 1000 });
        await page.goto(BASE + '/order-testing/store/' + fx.store, { waitUntil: 'networkidle0' });

        // ---- the listing is down to five columns --------------------------------------------------
        const headers = await page.$$eval('.a2t-conversions thead th', (n) => n.map((h) => h.textContent.trim()));
        check('five columns', headers.length === 5, JSON.stringify(headers));
        check('no Text to Audio header', !headers.includes('Text to Audio'));
        check('no Actions header', !headers.includes('Actions'));

        // Every row has as many cells as there are headers, or the table is drawn out of step.
        const widths = await page.$$eval('.a2t-conversions tbody tr', (rows) =>
            [...new Set(rows.map((r) => r.querySelectorAll(':scope > td').length))]);
        check('every row has five cells', widths.length === 1 && widths[0] === 5, JSON.stringify(widths));

        // ---- and the transcript is reachable from the dialog ---------------------------------------
        const opener = await page.$('[data-a2t-order-open]');
        check('View conversation is offered', opener !== null);

        if (opener === null) {
            continue;
        }

        await opener.click();
        await page.waitForSelector('#a2t-review-dialog[open]', { timeout: 5000 });
        await sleep(400);

        check('Original transcript is offered in the dialog',
            await visible(page, '[data-a2t-review-transcripts]'));
        check('it points at an order-testing endpoint',
            (await page.$eval('[data-a2t-review-transcripts]', (b) =>
                b.getAttribute('data-a2t-transcripts') || '')).startsWith('/order-testing/store/'));
        // The controls it sits beside must still be there — this replaced a column, not them.
        check('Open full editor is still beside it', await visible(page, '[data-a2t-full-editor]'));

        await page.click('[data-a2t-review-transcripts]');
        await page.waitForSelector('#a2t-transcript-dialog[open]', { timeout: 5000 });
        await sleep(600);

        const text = await page.$eval('#a2t-transcript-dialog', (d) => d.textContent);
        check('the transcript dialog opened', true);
        check('it says nothing about being unavailable', !text.includes('No original transcript is available'));
        // The seeded words, so this is the real transcript and not an empty shell that merely opened.
        check('real transcript text arrived', text.includes('pepperoni') || text.includes('delivery'),
            text.replace(/\s+/g, ' ').slice(0, 90));

        // Closing the transcript must leave the conversation open underneath, not take both away.
        await page.keyboard.press('Escape');
        await sleep(300);
        check('the conversation is still open underneath',
            await page.$eval('#a2t-review-dialog', (d) => d.open));

        await page.keyboard.press('Escape');
        await sleep(200);
    }

    const csp = await page.evaluate(() => window.__csp || []);
    check('no CSP violation', csp.length === 0, csp.join(' | '));

    // The seed writes rows, not bytes: `retained_audio_path` names a file that was never created, so
    // the player in the dialog asks for it and is told 404. That is the fixture, not the page — the
    // listing on its own logs nothing, and the other suites here print their errors for the same
    // reason rather than failing on them. Anything that is NOT that is a real fault and fails.
    var real = errors.filter(function (e) { return !/404/.test(e); });
    check('no console or page errors', real.length === 0, real.join(' | '));
    console.log('\nSuppressed (no audio bytes in the fixture): ' + errors.length + ' × 404');

    await browser.close();
    console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
