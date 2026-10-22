/*
 * The Seq and View Receipt columns, measured in a real browser.
 *
 * Markup tests already prove the cells exist and that the colgroup and the header row describe the
 * same number of columns. What they cannot prove is the thing that actually breaks: this table is
 * `table-layout: fixed` with percentage widths, and adding two columns to nine means every one of the
 * other nine had to give something up. If those percentages no longer sum to 100, or if a cell refuses
 * to wrap, the table paints wider than its wrapper and the page grows a horizontal scrollbar — which
 * is invisible to PhpBrowser and obvious to whoever uses the page.
 *
 * Every number below is read from `getBoundingClientRect`, not from a stylesheet.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/order-receipt-columns.js
 */
const { BASE, launch, signIn } = require('./lib');
const fx = require('./fixtures-topics.json');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** Sub-pixel rounding is not an overflow; a column's worth of it is. */
const TOLERANCE = 1.5;

/** The table as the browser actually laid it out. */
const layout = (page) => page.evaluate(() => {
    const table = document.querySelector('table.a2t-orders');

    if (table === null) {
        return null;
    }

    const wrapper = table.parentElement;
    const headers = Array.from(table.querySelectorAll('thead th'));

    return {
        headers: headers.map((th) => ({
            text: th.textContent.trim(),
            width: Math.round(th.getBoundingClientRect().width),
            // Has the label wrapped? Measured against the element's own font size rather than its
            // computed `line-height`, which is `normal` here and parses to NaN — a comparison
            // against NaN is false, so that version reported every header as wrapped including the
            // empty one.
            height: Math.round(th.getBoundingClientRect().height),
            fontSize: Math.round(parseFloat(getComputedStyle(th).fontSize)),
        })),
        cols: table.querySelectorAll('colgroup col').length,
        tableWidth: table.getBoundingClientRect().width,
        wrapperWidth: wrapper.getBoundingClientRect().width,
        wrapperScrollWidth: wrapper.scrollWidth,
        /*
         * Cells painting outside their own column, which is what makes the wrapper scroll.
         *
         * Reported per column rather than as one number for the table, because some of these are
         * older than this change — the "Order not found" text in the Demo URL column has never fitted
         * at 1280px. What must hold is that the two columns added here are not among them, and that
         * adding them did not push an existing one over.
         */
        overflowing: Array.from(table.querySelectorAll('tbody tr:first-child td, thead th'))
            .map((cell, i) => ({
                column: i,
                text: cell.textContent.trim().slice(0, 20),
                over: cell.scrollWidth - cell.clientWidth,
            }))
            .filter((c) => c.over > 1),
        documentScrollWidth: document.documentElement.scrollWidth,
        documentClientWidth: document.documentElement.clientWidth,
        seq: Array.from(document.querySelectorAll('.ot-seq')).map((s) => s.textContent.trim()),
        receipts: Array.from(document.querySelectorAll('a.ot-receipt')).map((a) => ({
            href: a.getAttribute('href'),
            target: a.getAttribute('target'),
            rel: a.getAttribute('rel'),
            width: Math.round(a.getBoundingClientRect().width),
        })),
    };
});

(async () => {
    const { browser, page } = await launch();

    try {
        await signIn(page, fx);

        for (const width of [1920, 1440, 1280]) {
            await page.setViewport({ width, height: 1000 });
            await page.goto(BASE + '/order-testing/store/' + fx.store, { waitUntil: 'networkidle0' });

            const l = await layout(page);

            if (l === null) {
                check('the orders table rendered at ' + width, false);
                continue;
            }

            check(width + ': eleven columns, header and colgroup agreeing',
                l.cols === l.headers.length && l.cols === 11,
                l.cols + ' cols / ' + l.headers.length + ' headers');

            check(width + ': Seq sits immediately after Order No#',
                l.headers[1]?.text === 'Order No#' && l.headers[2]?.text === 'Seq',
                l.headers.slice(0, 4).map((h) => h.text).join(' | '));

            check(width + ': View Receipt is the last column',
                l.headers[l.headers.length - 1]?.text === 'View Receipt',
                l.headers[l.headers.length - 1]?.text);

            // The one that catches a broken width budget.
            check(width + ': the page has no horizontal scrollbar',
                l.documentScrollWidth <= l.documentClientWidth + TOLERANCE,
                l.documentScrollWidth + ' vs ' + l.documentClientWidth);

            // The table box itself: `table-layout: fixed` plus percentages that sum to 100 means this
            // is exact, and a width budget that no longer adds up shows here first.
            check(width + ': the table box is exactly its wrapper\'s width',
                Math.abs(l.tableWidth - l.wrapperWidth) <= TOLERANCE,
                l.tableWidth.toFixed(1) + ' vs ' + l.wrapperWidth.toFixed(1));

            // The columns this change added must not be the ones painting outside themselves.
            const mine = l.overflowing.filter((c) => /^(Seq|View Receipt|\d+#\d+)$/.test(c.text));

            check(width + ': neither new column paints outside itself',
                mine.length === 0,
                mine.map((c) => c.text + ' +' + c.over + 'px').join(', ') || 'none');

            // And the Status badge, which is the one the width budget broke on the first attempt.
            const status = l.overflowing.filter((c) => c.text === 'Completed' || c.text === 'Status');

            check(width + ': the Status badge still fits its column',
                status.length === 0,
                status.map((c) => c.text + ' +' + c.over + 'px').join(', ') || 'none');

            /*
             * …and it still fits when the status is the LONGEST one, not the one this store happens
             * to hold.
             *
             * This is the case that reached a live store. The column was sized against "Completed"
             * (87px) because that is all the dev database contains, while the real enum goes up to
             * "Transcription requested" (167px) — and with `nowrap` the badge painted straight
             * through the Verification column. Every label is tried here, so the next person to
             * adjust these widths is measured against the worst one.
             */
            const worst = await page.evaluate(() => {
                const labels = ['Transcription requested', 'Ready for transcription',
                    'Partially completed', 'Transcribing', 'Completed', 'Failed'];
                const badge = document.querySelector('tbody .a2t-badge');

                if (badge === null) {
                    return null;
                }

                const was = badge.textContent;
                const bad = [];

                for (const label of labels) {
                    badge.textContent = label;
                    const cell = badge.closest('td');

                    if (cell.scrollWidth > cell.clientWidth + 1) {
                        bad.push(label + ' +' + (cell.scrollWidth - cell.clientWidth) + 'px');
                    }
                }

                badge.textContent = was;

                return bad;
            });

            check(width + ': every status label in the enum fits, not just this store\'s',
                worst !== null && worst.length === 0,
                worst === null ? 'no badge on the page' : (worst.join(', ') || 'all six fit'));

            // A header noticeably taller than "Seq" — which is three letters and cannot wrap — has
            // wrapped. Compared against a peer rather than against a line-height, because the
            // computed `line-height` here is `normal` and parses to NaN.
            const seqHeader = l.headers.find((h) => h.text === 'Seq');
            const wrapped = l.headers.filter(
                (h) => h.text !== '' && seqHeader !== undefined && h.height > seqHeader.height + 4,
            );

            check(width + ': no header label wrapped onto a second line',
                wrapped.length === 0,
                wrapped.map((h) => h.text + ' ' + h.height + 'px vs ' + seqHeader?.height).join(', ') || 'none');

            check(width + ': the Seq column is the narrowest of the data columns',
                l.headers[2].width < l.headers[3].width,
                'seq ' + l.headers[2].width + 'px vs conversation ' + l.headers[3].width + 'px');
        }

        // ---------------------------------------------------------------- content, once
        await page.setViewport({ width: 1920, height: 1000 });
        await page.goto(BASE + '/order-testing/store/' + fx.store, { waitUntil: 'networkidle0' });

        const l = await layout(page);

        check('the provider\'s own seq is printed verbatim',
            l.seq.some((s) => /^\d+#\d+$/.test(s)),
            l.seq.join(', ') || 'none');

        check('every receipt link is this application\'s own URL',
            l.receipts.length > 0 && l.receipts.every((r) => /^\/order-testing\/store\/\d+\/order\/\d+\/receipt$/.test(r.href)),
            l.receipts.map((r) => r.href).join(' , ') || 'none');

        check('every receipt link opens a new tab, with no handle back on this one',
            l.receipts.every((r) => r.target === '_blank' && r.rel === 'noopener noreferrer'));

        check('the receipt button fits its column',
            l.receipts.every((r) => r.width <= l.headers[l.headers.length - 1].width + TOLERANCE),
            'widest ' + Math.max(...l.receipts.map((r) => r.width)) + 'px in '
            + l.headers[l.headers.length - 1].width + 'px');

        // Nothing is fetched upstream while the table loads: the provider's host must not appear in
        // the document at all, and no request may have gone to it.
        const html = await page.content();

        check('the page names neither the provider nor a credential',
            !html.includes('order58.com') && !html.includes('Bearer'),
            'clean');
    } catch (e) {
        check('the run completed', false, e.message);
    } finally {
        await browser.close();
    }

    console.log(failures === 0 ? '\norder-receipt-columns: OK' : '\norder-receipt-columns: ' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
