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

/** One cell's geometry: is the name beside the control, and how tall is the row? */
const cells = (page) => page.evaluate(() => Array.from(
    document.querySelectorAll('.practice-topic-cell'),
).map((cell) => {
    const name = cell.querySelector('.practice-topic-cell__name');
    const select = cell.querySelector('.practice-assign__select');
    const row = cell.closest('tr');

    return {
        hasSelect: select !== null,
        sameLine: name !== null && select !== null
            ? Math.abs(
                (name.getBoundingClientRect().top + name.getBoundingClientRect().height / 2)
                    - (select.getBoundingClientRect().top + select.getBoundingClientRect().height / 2),
            ) < 6
            : null,
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

            check('name and control share a line at ' + width,
                shaped.length > 0 && shaped.every((c) => c.sameLine === true),
                JSON.stringify(shaped));
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
