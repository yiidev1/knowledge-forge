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

/* Where the review screenshots land. A real look at the page is part of the check, not a nicety. */
const SHOTS = process.env.KF_SHOTS || require('os').tmpdir();

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
                width: box.width,
                // The totals rows are full-bleed grid rows inset by padding, so the row BOX still
                // starts at the panel edge. What sits bottom-right is their content.
                totalsLeft: (() => {
                    const term = panel.querySelector('.ot-book__line--grand .ot-book__term');
                    return term === null ? null : term.getBoundingClientRect().left;
                })(),
                subtotalLeft: (() => {
                    const row = panel.querySelector(
                        '.ot-book__section--totals .ot-book__line:not(.ot-book__line--grand) .ot-book__term',
                    );
                    return row === null ? null : row.getBoundingClientRect().left;
                })(),
                grandTop: (() => {
                    const g = panel.querySelector('.ot-book__line--grand');
                    return g === null ? null : g.getBoundingClientRect().top + window.scrollY;
                })(),
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

/**
 * What a reader actually sees, after every translucent layer is composited.
 *
 * A tinted panel is declared as `rgba(…, 0.1)`, so reading its colour alone measures a colour nobody
 * ever looks at: the number that matters is the one the browser paints, which is that tint over
 * whatever is behind it. Walking outward until an opaque layer is found and compositing back inward is
 * the only way to get it — and it is exactly the step whose absence made an earlier contrast probe
 * report failures that were not there.
 */
function readContrast(page, selector) {
    return page.evaluate((sel) => {
        const el = document.querySelector(sel);

        if (el === null) {
            return null;
        }

        const parse = (value) => {
            const parts = (value || '').match(/[\d.]+/g);
            if (parts === null) {
                return { r: 0, g: 0, b: 0, a: 0 };
            }
            return {
                r: Number(parts[0]), g: Number(parts[1]), b: Number(parts[2]),
                a: parts[3] === undefined ? 1 : Number(parts[3]),
            };
        };

        const over = (fg, bg) => ({
            r: fg.r * fg.a + bg.r * (1 - fg.a),
            g: fg.g * fg.a + bg.g * (1 - fg.a),
            b: fg.b * fg.a + bg.b * (1 - fg.a),
            a: 1,
        });

        const luminance = (c) => {
            const f = (v) => {
                const n = v / 255;
                return n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
        };

        // Outward to the root, collecting each layer's own colour.
        const layers = [];
        for (let node = el; node !== null; node = node.parentElement) {
            layers.push(parse(getComputedStyle(node).backgroundColor));
        }

        // Then inward from the canvas, painting each one on.
        let bg = { r: 255, g: 255, b: 255, a: 1 };
        for (let i = layers.length - 1; i >= 0; i--) {
            if (layers[i].a > 0) {
                bg = over(layers[i], bg);
            }
        }

        const style = getComputedStyle(el);
        const text = over(parse(style.color), bg);
        const l1 = luminance(text);
        const l2 = luminance(bg);
        const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);

        return {
            ratio: Math.round(ratio * 100) / 100,
            color: style.color,
            background: 'rgb(' + [bg.r, bg.g, bg.b].map((v) => Math.round(v)).join(',') + ')',
            size: Math.round(parseFloat(style.fontSize) * 10) / 10,
            weight: style.fontWeight,
            text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 24),
        };
    }, selector);
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

        // The totals belong in the bottom right of their own panel, measured from the panel box so
        // the check holds at any width.
        for (const [name, p] of [['original', original], ['demo', demo]]) {
            const share = p.totalsLeft === null ? 0 : (p.totalsLeft - p.left) / p.width;
            check('the grand total sits in the right-hand half (' + name + ')',
                p.totalsLeft !== null && share >= 0.40 && share <= 0.60,
                'label starts at ' + (share * 100).toFixed(1) + '% of panel');

            // ...and only the grand total. The figures it sums keep the ordinary row position, which
            // is the same inset every other label on the page uses.
            const subShare = p.subtotalLeft === null ? 1 : (p.subtotalLeft - p.left) / p.width;
            check('the subtotals keep the ordinary row position (' + name + ')',
                p.subtotalLeft !== null && subShare < 0.15,
                'labels start at ' + (subShare * 100).toFixed(1) + '% of panel');
        }

        check('the two totals blocks line up',
            original.grandTop !== null && demo.grandTop !== null
            && Math.abs(original.grandTop - demo.grandTop) <= TOLERANCE,
            original.grandTop === null || demo.grandTop === null
                ? 'missing a grand total'
                : original.grandTop.toFixed(1) + ' vs ' + demo.grandTop.toFixed(1));
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

    // ---- the toolbar and the scores: measured, at the widths people actually use -----------------
    //
    // Both themes, because this application has no theme switch: the colours are chosen by
    // `prefers-color-scheme` alone, so the only way to see the other one is to ask the browser for it.
    for (const scheme of ['light', 'dark']) {
        await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);

        for (const width of [1440, 1280, 1024, 768, 390]) {
            await page.setViewport({ width, height: 1200 });
            await page.goto(BASE + fx.url, { waitUntil: 'networkidle0' });
            await page.evaluate(() => document.fonts.ready);

            console.log('\nTOOLBAR — ' + scheme + ' theme, ' + width + 'px');

            const t = await page.evaluate(() => {
                const box = (el) => {
                    if (el === null) {
                        return null;
                    }
                    const r = el.getBoundingClientRect();
                    return {
                        left: Math.round(r.left * 10) / 10,
                        right: Math.round(r.right * 10) / 10,
                        top: Math.round(r.top * 10) / 10,
                        bottom: Math.round(r.bottom * 10) / 10,
                        width: Math.round(r.width * 10) / 10,
                        height: Math.round(r.height * 10) / 10,
                    };
                };

                const toolbar = document.querySelector('.ot-toolbar');
                const actions = document.querySelector('.ot-toolbar__actions');
                const score = document.querySelector('.ot-score');
                const buttons = [...document.querySelectorAll('.ot-toolbar__actions .btn')].map(box);
                const cards = [...document.querySelectorAll('.ot-book--demo .ot-sscore')].map((el) => ({
                    ...box(el),
                    value: el.querySelector('.ot-sscore__value'),
                }));

                return {
                    toolbar: box(toolbar),
                    actions: box(actions),
                    score: box(score),
                    scoreInToolbar: toolbar !== null && toolbar.contains(score),
                    buttons,
                    cards: cards.map((c) => ({
                        left: c.left, right: c.right, width: c.width, height: c.height,
                        // "Not scored" is words, and words are set at a label's size on purpose.
                        isNumber: c.value !== null && c.value.textContent.trim().endsWith('%'),
                        size: c.value === null
                            ? 0
                            : Math.round(parseFloat(getComputedStyle(c.value).fontSize) * 10) / 10,
                        metaWraps: (() => {
                            const meta = document.querySelector('.ot-book--demo .ot-sscore__meta');
                            return meta !== null && meta.getBoundingClientRect().width > c.width;
                        })(),
                    })),
                    summaryTop: box(document.querySelector('.card')).top,
                    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                };
            });

            // Whether the figure ended up on a line of its own, which is a layout fact to be read off
            // the page rather than guessed from the viewport width.
            const wrapped = t.score.top >= t.actions.bottom - 1;
            const heights = t.buttons.map((b) => b.height);
            const spread = Math.max(...heights) - Math.min(...heights);

            check('the figure sits in the toolbar, not the page heading (' + width + ')', t.scoreInToolbar);
            check('the action group starts at the row left edge (' + width + ')',
                Math.abs(t.actions.left - t.toolbar.left) <= 1,
                t.actions.left + ' vs ' + t.toolbar.left);
            check('the figure ends at the row right edge (' + width + ')',
                Math.abs(t.score.right - t.toolbar.right) <= 1,
                t.score.right + ' vs ' + t.toolbar.right);
            check('the three buttons are one height (' + width + ')', spread <= 1,
                heights.join(' / '));
            check('…and that height is a control height (' + width + ')',
                heights.every((h) => h >= 34 && h <= 46), heights[0] + 'px');
            check('nothing in the row overlaps (' + width + ')',
                wrapped ? t.actions.bottom <= t.score.top + 1 : t.actions.right <= t.score.left + 1,
                wrapped ? 'wrapped onto its own line' : t.actions.right + ' / ' + t.score.left);
            check('the row sits above the order summary (' + width + ')',
                t.toolbar.bottom <= t.summaryTop + 1);
            check('no horizontal page scroll (' + width + ')', t.overflow <= 0, String(t.overflow));

            // The score card is a card, not a chip: it has to be big enough to read across the page.
            check('the figure card is a readable size (' + width + ')',
                t.score.width >= 140 && t.score.height >= 60,
                t.score.width + ' x ' + t.score.height);

            // Section cards: one size, one right edge, one readable figure.
            if (t.cards.length > 0) {
                const widths = [...new Set(t.cards.map((c) => c.width))];
                const cardHeights = [...new Set(t.cards.map((c) => c.height))];
                const edges = [...new Set(t.cards.map((c) => Math.round(c.right)))];

                check('every section card is the same width (' + width + ')', widths.length === 1,
                    widths.join(' / '));
                check('every section card is the same height (' + width + ')', cardHeights.length === 1,
                    cardHeights.join(' / '));
                check('they all end on one right-hand edge (' + width + ')', edges.length === 1,
                    edges.join(' / '));
                const numbered = t.cards.filter((c) => c.isNumber);
                check('the section figure is readable (' + width + ')',
                    numbered.length > 0 && numbered.every((c) => c.size >= 16),
                    numbered.length + ' scored, ' + (numbered[0] || {}).size
                    + 'px, card ' + t.cards[0].width + ' x ' + t.cards[0].height);
                check('the coverage line fits inside its card (' + width + ')',
                    t.cards.every((c) => !c.metaWraps));
            }

            if (width === 1440) {
                await page.screenshot({
                    path: SHOTS + '/compare-' + scheme + '-' + width + '.png',
                    fullPage: true,
                });
                console.log('  shot    ' + SHOTS + '/compare-' + scheme + '-' + width + '.png');
            }

            if (width === 390) {
                await page.screenshot({ path: SHOTS + '/compare-' + scheme + '-390.png' });
                console.log('  shot    ' + SHOTS + '/compare-' + scheme + '-390.png');
            }
        }

        // Contrast, on the composited colours rather than the declared ones.
        await page.setViewport({ width: 1440, height: 1200 });
        await page.goto(BASE + fx.url, { waitUntil: 'networkidle0' });

        for (const [selector, floor, note] of [
            ['.ot-score__value', 3, 'large'],
            ['.ot-score__label', 4.5, 'small'],
            ['.ot-score__scope', 4.5, 'small'],
            ['.ot-book--demo .ot-sscore__value', 4.5, 'small'],
            ['.ot-book--demo .ot-sscore__meta', 4.5, 'small'],
            ['.ot-toolbar__actions .btn', 4.5, 'small'],
        ]) {
            const measured = await readContrast(page, selector);
            check('contrast ' + selector + ' (' + scheme + ', ' + note + ')',
                measured !== null && measured.ratio >= floor,
                measured === null
                    ? 'not rendered'
                    : measured.ratio + ':1 — "' + measured.text + '" ' + measured.color
                      + ' on ' + measured.background + ', ' + measured.size + 'px/' + measured.weight);
        }

        check('section scores are on the demo panel only (' + scheme + ')',
            await page.evaluate(() => document.querySelectorAll('.ot-book--demo .ot-sscore').length > 0
                && document.querySelectorAll('.ot-book--original .ot-sscore').length === 0));
    }

    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    const csp = await page.evaluate(() => window.__csp || []);
    check('no CSP violation', csp.length === 0, csp.join(' | '));
    check('no console or page errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
