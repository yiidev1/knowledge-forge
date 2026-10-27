/*
 * The Order Testing topic controls, measured in a real browser.
 *
 * Layout is the thing a markup test cannot see: whether a name and its control share a line, whether a
 * toolbar is one row or three, and whether any of it survives a narrower window. Every number below is
 * read from `getBoundingClientRect`, not from a stylesheet.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/order-testing-topics.js
 */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures-topics.json');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/**
 * One cell's geometry.
 *
 * On this surface the dropdown IS the answer: it shows the assigned topic as its selected option, so
 * the name that used to sit beside it was the same fact twice in a column with room for one. What is
 * measured here is therefore that the name is gone and the control is still compact.
 */
const cells = (page) => page.evaluate(() => Array.from(
    document.querySelectorAll('.practice-topic-cell'),
).map((cell) => {
    const select = cell.querySelector('.practice-assign__select');
    const row = cell.closest('tr');

    return {
        hasSelect: select !== null,
        // The read-only presentation, which must NOT appear where the dropdown does.
        hasName: cell.querySelector('.practice-topic__value') !== null,
        selected: select === null ? null : select.options[select.selectedIndex].textContent.trim(),
        selectWidth: select === null ? null : Math.round(select.getBoundingClientRect().width),
        selectHeight: select === null ? null : Math.round(select.getBoundingClientRect().height),
        rowHeight: Math.round(row.getBoundingClientRect().height),
    };
}));

async function run() {
    const { browser, page, errors } = await launch();

    try {
        await signIn(page, fx);

        const url = BASE + '/order-testing/store/' + fx.store;

        for (const width of [1920, 1440, 1366]) {
            await page.setViewport({ width, height: 1000 });
            await page.goto(url, { waitUntil: 'networkidle0' });

            const shaped = (await cells(page)).filter((c) => c.hasSelect);

            check('the topic is shown once, by the dropdown, at ' + width,
                shaped.length > 0 && shaped.every((c) => c.hasName === false),
                JSON.stringify(shaped));
            check('and the dropdown carries the assigned topic at ' + width,
                shaped.every((c) => typeof c.selected === 'string' && c.selected !== ''),
                shaped.map((c) => c.selected).join(' | '));
            check('the control is compact at ' + width,
                shaped.every((c) => c.selectWidth >= 120 && c.selectWidth <= 200
                    && c.selectHeight >= 26 && c.selectHeight <= 40),
                shaped.map((c) => c.selectWidth + 'x' + c.selectHeight).join(', '));
            check('no sideways page scroll at ' + width, await page.evaluate(
                () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
            ));
        }

        // ---------------------------------------------------------------- the bulk toolbar
        await page.setViewport({ width: 1440, height: 1000 });
        await page.goto(url, { waitUntil: 'networkidle0' });

        check('the toolbar is hidden until something is selected', await page.evaluate(
            () => document.querySelector('#practice-bulk-form').hidden,
        ));

        await page.click('tbody [data-practice-select]');
        await sleep(300);

        const bar = await page.evaluate(() => {
            const form = document.querySelector('#practice-bulk-form');
            const count = form.querySelector('[data-practice-count]');
            const select = form.querySelector('[data-practice-bulk-topic]');
            const button = form.querySelector('[data-practice-bulk-submit]');
            const mid = (el) => el.getBoundingClientRect().top + el.getBoundingClientRect().height / 2;

            return {
                hidden: form.hidden,
                text: count.textContent.trim(),
                oneRow: Math.abs(mid(count) - mid(select)) < 6 && Math.abs(mid(select) - mid(button)) < 6,
                order: count.getBoundingClientRect().left < select.getBoundingClientRect().left
                    && select.getBoundingClientRect().left < button.getBoundingClientRect().left,
                selectWidth: Math.round(select.getBoundingClientRect().width),
                height: Math.round(form.getBoundingClientRect().height),
                defaultValue: select.value,
                defaultLabel: select.options[select.selectedIndex].textContent.trim(),
                disabled: button.disabled,
            };
        });

        check('selecting a row shows the toolbar', bar.hidden === false);
        check('it counts the selection', bar.text === '1 selected', bar.text);
        check('count, topic and button sit on one row', bar.oneRow, JSON.stringify(bar));
        check('in that order', bar.order);
        check('the toolbar is one row tall', bar.height <= 64, String(bar.height));
        check('the bulk topic is compact', bar.selectWidth >= 150 && bar.selectWidth <= 260,
            String(bar.selectWidth));

        // ---------------------------------------------------------------- Not Selected
        check('it defaults to Not Selected', bar.defaultValue === '' && bar.defaultLabel === 'Not Selected',
            bar.defaultLabel);
        check('and the button is disabled until a topic is chosen', bar.disabled === true);

        await page.select('[data-practice-bulk-topic]', String(fx.topicB.id));
        await sleep(200);

        check('choosing a topic enables it', await page.evaluate(
            () => document.querySelector('[data-practice-bulk-submit]').disabled === false,
        ));

        // Pressing it opens the confirmation rather than saving.
        const before = page.url();
        await page.click('[data-practice-bulk-submit]');
        await sleep(300);

        check('pressing it confirms rather than saves', await page.evaluate(
            () => document.querySelector('#practice-bulk-confirm-dialog').matches(':modal'),
        ) && page.url() === before, page.url());

        await page.click('#practice-bulk-confirm-dialog [data-practice-dialog-close]');
        await sleep(200);

        // Back to Not Selected: nothing may be sent.
        await page.select('[data-practice-bulk-topic]', '');
        await sleep(200);
        await page.click('[data-practice-bulk-submit]');
        await sleep(300);

        check('Not Selected sends nothing', await page.evaluate(
            () => document.querySelector('#practice-bulk-confirm-dialog').open === false,
        ) && page.url() === before, page.url());

        // ---------------------------------------------------------------- the control shows its state
        //
        // Measured, not asserted from the class name: the point of the change is that an assigned row
        // LOOKS different, so the two backgrounds have to actually differ on screen.
        await page.setViewport({ width: 1440, height: 1000 });
        await page.goto(url, { waitUntil: 'networkidle0' });

        const states = await page.evaluate((assigned, free) => {
            const read = (order) => {
                const el = document.querySelector('#practice-topic-' + order);

                if (el === null) {
                    return null;
                }

                const style = getComputedStyle(el);

                return {
                    marked: el.classList.contains('practice-assign__select--assigned'),
                    background: style.backgroundColor,
                    border: style.borderTopColor,
                    selected: el.options[el.selectedIndex].textContent.trim(),
                };
            };

            return { assigned: read(assigned), free: read(free) };
        }, fx.assignedOrder, fx.freeOrder);

        check('an assigned row is marked', states.assigned !== null && states.assigned.marked === true,
            JSON.stringify(states.assigned));
        check('an unassigned row is not', states.free !== null && states.free.marked === false,
            JSON.stringify(states.free));
        check('and the two really look different',
            states.assigned.background !== states.free.background
                || states.assigned.border !== states.free.border,
            JSON.stringify(states));
        check('the unassigned one still invites a choice',
            states.free.selected === 'Select Topic', states.free.selected);

        // Choosing does not change what the row SAYS about itself: the colour is the saved state, and
        // nothing is saved until the confirmation is confirmed.
        await page.select('#practice-topic-' + fx.freeOrder, String(fx.topicB.id));
        await sleep(400);

        check('choosing a topic does not mark the row yet', await page.evaluate(
            (order) => document.querySelector('#practice-topic-' + order)
                .classList.contains('practice-assign__select--assigned') === false,
            fx.freeOrder,
        ));

        await page.click('#practice-confirm-dialog [data-practice-dialog-close]');
        await sleep(300);

        check('and Cancel leaves it neutral', await page.evaluate(
            (order) => {
                const el = document.querySelector('#practice-topic-' + order);

                return el.classList.contains('practice-assign__select--assigned') === false
                    && el.options[el.selectedIndex].textContent.trim() === 'Select Topic';
            },
            fx.freeOrder,
        ));

        // ---------------------------------------------------------------- the OPEN list is readable
        //
        // A native option list carries no background of its own: it is painted with the select's
        // `background-color`. A translucent one is therefore composited over the platform's own popup
        // base — light, on Chrome for Linux — and a list of light text opened as light-on-light,
        // readable only where the hover highlight happened to be. The closed control looked perfect
        // throughout, which is why this is measured on the options and not on the control.
        //
        // Every select on the page, so a tint added to a future variant cannot bring it back.
        const lists = await page.evaluate(() => {
            const luminance = (css) => {
                const [r, g, b] = css.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => {
                    const s = v > 1 ? v / 255 : v;

                    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
                });

                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const ratio = (a, b) => {
                const x = luminance(a);
                const y = luminance(b);

                return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
            };

            return Array.from(document.querySelectorAll('select.field__control')).map((el) => {
                const style = getComputedStyle(el);
                const worst = Array.from(el.options).reduce((lowest, option) => {
                    const os = getComputedStyle(option);

                    return Math.min(lowest, ratio(os.backgroundColor, os.color));
                }, Infinity);

                return {
                    id: el.id || el.name,
                    background: style.backgroundColor,
                    // A colour carrying any alpha below 1 is the defect.
                    translucent: /\/\s*0?\.\d/.test(style.backgroundColor)
                        || /rgba\([^)]*,\s*0?\.\d+\)/.test(style.backgroundColor),
                    worstContrast: Number(worst.toFixed(2)),
                };
            });
        });

        check('every topic control has an opaque background',
            lists.length > 0 && lists.every((l) => l.translucent === false), JSON.stringify(lists));
        check('so every option in every list is readable without hovering',
            lists.every((l) => l.worstContrast >= 7), JSON.stringify(lists));

        // ---------------------------------------------------------------- the store card
        await page.goto(BASE + '/order-testing?q=' + encodeURIComponent(fx.storeName || ''), {
            waitUntil: 'networkidle0',
        });

        const card = await page.evaluate(() => {
            const metrics = Array.from(document.querySelectorAll('.store-card__metric'));

            return metrics.map((m) => ({
                value: m.querySelector('.store-card__metric-value').textContent.trim(),
                label: m.querySelector('.store-card__metric-label').textContent.replace(/\s+/g, ' ').trim(),
                height: Math.round(m.getBoundingClientRect().height),
            }));
        });

        check('the card carries exactly two metrics', card.length === 2, JSON.stringify(card));
        check('named for what they count',
            /Audio Recordings/.test(card[0].label) && /Topics Assigned/.test(card[1].label),
            JSON.stringify(card.map((c) => c.label)));
        // The label alone — the "Orders with topics" subtitle was removed at the owner's request.
        check('the second is labelled Topics Assigned and nothing else',
            card[1].label === 'Topics Assigned', card[1].label);
        check('both panels the same height', card[0].height === card[1].height,
            card.map((c) => c.height).join(' vs '));

        // One assignment in the fixture, so the number is 1 — and it is the count of assigned ORDERS,
        // which is the whole point of the change.
        check('Topics Assigned counts assigned orders', card[1].value === '1', card[1].value);
        check('and the audio count is unchanged', card[0].value === '3', card[0].value);

        // ---------------------------------------------------------------- Verify, in BOTH themes
        //
        // The application has one theme mechanism — `prefers-color-scheme` — and a control styled for
        // only one of them passes every other test in this file. The first version of this button took
        // its text colour from `--primary-strong`, a token that does not exist, so every reader got a
        // pale fallback meant for a dark surface: on the light theme it read as disabled.
        //
        // Colours are composited before they are judged. A `color-mix(..., transparent)` background is
        // a real colour with an alpha, and comparing it as if it were opaque reports a failure where
        // there is none — and, worse, a pass where there is one.
        for (const scheme of ['light', 'dark']) {
            await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);
            await page.goto(url, { waitUntil: 'networkidle0' });

            // Declared inside the page on every pass: a navigation wipes anything installed before it.
            const m = await page.evaluate(() => {
                const parse = (css) => {
                    const n = css.match(/[\d.]+/g).map(Number);

                    return css.startsWith('color(')
                        ? [n[0] * 255, n[1] * 255, n[2] * 255, n.length > 3 ? n[3] : 1]
                        : [n[0], n[1], n[2], n.length > 3 ? n[3] : 1];
                };
                const over = (f, b) => {
                    const a = f[3];

                    return [f[0] * a + b[0] * (1 - a), f[1] * a + b[1] * (1 - a), f[2] * a + b[2] * (1 - a), 1];
                };
                const lum = (c) => {
                    const [r, g, b] = c.slice(0, 3).map((v) => {
                        const s = v / 255;

                        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
                    });

                    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
                };
                const ratio = (a, b) => {
                    const x = lum(a);
                    const y = lum(b);

                    return Number(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)).toFixed(2));
                };

                const el = document.querySelector('.ot-verify__button');

                if (el === null) {
                    return null;
                }

                const cs = getComputedStyle(el);
                let node = el.parentElement;
                let behindCss = 'rgba(0, 0, 0, 0)';

                while (node && /rgba\(0, 0, 0, 0\)/.test(behindCss)) {
                    behindCss = getComputedStyle(node).backgroundColor;
                    node = node.parentElement;
                }

                const behind = parse(behindCss);
                const surface = over(parse(cs.backgroundColor), behind);

                return {
                    text: ratio(surface, over(parse(cs.color), surface)),
                    boundary: ratio(behind, over(parse(cs.borderTopColor), behind)),
                };
            });

            check('Verify is readable on the ' + scheme + ' theme',
                m !== null && m.text >= 4.5, JSON.stringify(m));
            check('and its edge is visible on the ' + scheme + ' theme',
                m !== null && m.boundary >= 3, JSON.stringify(m));
        }

        await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
        await page.goto(url, { waitUntil: 'networkidle0' });

        // ---------------------------------------------------------------- the filter closes
        //
        // A <details> has no notion of "outside", so until this was added the panel sat open over the
        // table until its own summary was pressed again. Every assertion here is about closing: the
        // opening, the ticking and the applying are the browser's and the server's, and are checked
        // above.
        await page.setViewport({ width: 1440, height: 1000 });
        await page.goto(url, { waitUntil: 'networkidle0' });

        const picker = '[data-practice-filter-picker]';
        const isOpen = () => page.evaluate((s) => document.querySelector(s).open, picker);

        await page.click(picker + ' summary');
        await sleep(200);
        check('the filter opens', await isOpen() === true);

        // Ticking a choice must NOT close it: a multi-select that shuts on the first tick cannot be
        // used for what it is for.
        await page.click(picker + ' [data-practice-filter-choice]');
        await sleep(200);
        check('ticking a choice keeps it open', await isOpen() === true);
        check('and the choice is ticked', await page.evaluate(
            (s) => document.querySelector(s + ' [data-practice-filter-choice]').checked, picker,
        ));

        // Clicking the page outside it closes it, and does not apply or clear what was ticked.
        await page.click('h1');
        await sleep(200);
        check('clicking outside closes it', await isOpen() === false);
        check('without applying anything', page.url() === url, page.url());
        check('and without unticking what was chosen', await page.evaluate(
            (s) => document.querySelector(s + ' [data-practice-filter-choice]').checked, picker,
        ));

        // The summary still toggles it the way the browser always did.
        await page.click(picker + ' summary');
        await sleep(150);
        check('the summary opens it again', await isOpen() === true);
        await page.click(picker + ' summary');
        await sleep(150);
        check('and closes it again', await isOpen() === false);

        // Escape closes it and hands focus back to what opened it.
        await page.click(picker + ' summary');
        await sleep(150);
        await page.keyboard.press('Escape');
        await sleep(200);
        check('Escape closes it', await isOpen() === false);
        check('and focus returns to the summary', await page.evaluate(
            (s) => document.activeElement === document.querySelector(s + ' summary'), picker,
        ));

        // ---------------------------------------------------------------- narrow
        await page.setViewport({ width: 390, height: 850 });
        await page.goto(url, { waitUntil: 'networkidle0' });

        check('a phone-width page does not scroll sideways', await page.evaluate(
            () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
        ));
        check('the table takes the overflow instead', await page.evaluate(
            () => {
                const scroller = document.querySelector('.a2t-table-scroll');

                return scroller !== null && getComputedStyle(scroller).overflowX !== 'visible';
            },
        ));

        const csp = await page.evaluate(() => window.__csp || []);
        check('no Content-Security-Policy violations', csp.length === 0, csp.join(' | '));
        check('no page or console errors', errors.length === 0, errors.join(' | '));
    } finally {
        await browser.close();
    }

    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILED'));
    process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
