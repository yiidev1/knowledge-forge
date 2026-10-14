/*
 * Topic Management and the Practice Orders topic controls, in a real browser.
 *
 * Four of the things checked here are invisible to the Web suite, and three of them were real defects:
 *
 * - **the Edit dialog's values.** PhpBrowser follows the Edit link, so it always saw the server-rendered
 *   form. A browser runs the click handler first — and that handler used to open whichever dialog was
 *   already on the page, which was the empty Add form.
 * - **the filter not applying on change.** "Selecting an option must not navigate" is a statement about
 *   a `change` listener. Without a browser there is no change event and nothing to not happen.
 * - **a `<dialog>` actually opening as a modal.** `showModal()` either works or the element paints as a
 *   block under the page; both look identical in markup.
 * - **headers staying on one line.** Wrapping is layout, and layout needs a layout engine.
 *
 *   KF_BASE=http://127.0.0.1:8080 ./tests/E2E/run-topics.sh
 */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures-topics.json');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** The values the one dialog is currently showing. */
async function dialogValues(page) {
    return page.evaluate(() => {
        const dialog = document.querySelector('#practice-topic-dialog');
        const status = dialog.querySelector('#practice-topic-status');

        return {
            open: dialog.open,
            modal: dialog.matches(':modal'),
            title: dialog.querySelector('.source-modal__title').textContent.trim(),
            name: dialog.querySelector('#practice-topic-name').value,
            description: dialog.querySelector('#practice-topic-description').value,
            status: status === null ? null : status.value,
            action: dialog.querySelector('form').getAttribute('action'),
        };
    });
}

/** Is every one of this table's headers drawn on a single line? */
async function headerLines(page) {
    return page.evaluate(() => {
        const out = {};

        document.querySelectorAll('.practice-topics thead th').forEach((th) => {
            const label = th.textContent.trim();

            if (label === '') {
                return;
            }

            // A Range over the text itself: one client rect means one line, however the cell is styled.
            const range = document.createRange();
            range.selectNodeContents(th);
            out[label] = range.getClientRects().length;
        });

        return out;
    });
}

/**
 * The WCAG contrast ratio between each action button's text and what is actually painted behind it.
 *
 * Measured rather than eyeballed, and measured against the *resolved* colours: the state tokens flip
 * between the light and dark themes, so a rule that reads correctly in one can paint white on pale
 * mint in the other — which is exactly what happened to Activate.
 */
async function actionContrast(page) {
    return page.evaluate(() => {
        const channel = (c) => {
            const v = c / 255;

            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        };
        const luminance = (rgb) => {
            const [r, g, b] = rgb;

            return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
        };
        const parse = (value) => value.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);

        // What is behind it, which is not always the element itself.
        const painted = (el) => {
            let node = el;

            while (node !== null) {
                const bg = getComputedStyle(node).backgroundColor;

                if (bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
                    return parse(bg);
                }

                node = node.parentElement;
            }

            return [0, 0, 0];
        };

        const out = {};

        document.querySelectorAll('.practice-actions .btn').forEach((btn) => {
            const fg = luminance(parse(getComputedStyle(btn).color));
            const bg = luminance(painted(btn));
            const ratio = (Math.max(fg, bg) + 0.05) / (Math.min(fg, bg) + 0.05);

            out[btn.textContent.trim()] = Math.round(ratio * 100) / 100;
        });

        return out;
    });
}

const pageOverflows = (page) => page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
);

async function run() {
    const { browser, page, errors } = await launch();

    try {
        await signIn(page, fx);

        // ---------------------------------------------------------------- Topic Management
        await page.goto(BASE + '/admin/topics', { waitUntil: 'networkidle0' });

        const editA = 'a[href="/admin/topics?topic=' + fx.topicA.id + '"]';
        const editB = 'a[href="/admin/topics?topic=' + fx.topicB.id + '"]';

        check('both Edit links are rendered', await page.$(editA) !== null && await page.$(editB) !== null);

        // Clicking Edit — the real path, with the click handler in the way.
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(editA)]);
        let values = await dialogValues(page);

        check('Edit opens the dialog as a real modal', values.open && values.modal,
            'open=' + values.open + ' modal=' + values.modal);
        check('Edit is titled Edit Topic', values.title === 'Edit Topic', values.title);
        check('Edit shows that topic\'s name', values.name === fx.topicA.name, values.name);
        check('Edit shows that topic\'s description', values.description === fx.topicA.description,
            values.description);
        check('Edit posts to that topic\'s own address',
            values.action === '/admin/topics/' + fx.topicA.id, values.action);
        check('Edit offers the status, set to the stored one', values.status === 'ACTIVE', values.status);

        // The second topic, from the first one's page: the values must change with it.
        await page.goto(BASE + '/admin/topics', { waitUntil: 'networkidle0' });
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click(editB)]);
        values = await dialogValues(page);

        check('editing another topic shows ITS name', values.name === fx.topicB.name, values.name);
        check('editing another topic does not inherit the first description',
            values.description === '', JSON.stringify(values.description));
        check('and posts to ITS address', values.action === '/admin/topics/' + fx.topicB.id, values.action);

        // Close is a real link to the clean URL — that is the no-script fallback — which the script
        // upgrades to shutting the dialog where it stands. No navigation, so nothing is re-fetched.
        const editUrl = page.url();
        await page.click('#practice-topic-dialog [data-practice-dialog-close]');
        await sleep(200);

        check('closing shuts the dialog without reloading',
            (await dialogValues(page)).open === false && page.url() === editUrl, page.url());

        // Now Add, with an Edit dialog still the one rendered. It wants a different one, so it must
        // follow its link rather than show what is here — the regression this whole mechanism is for.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('[data-practice-dialog-for="new"]'),
        ]);
        values = await dialogValues(page);

        check('Add from an Edit page navigates to the Add address',
            page.url().endsWith('topic=new'), page.url());
        check('Add opens as a real modal', values.modal);
        check('Add is titled Add Topic', values.title === 'Add Topic', values.title);
        check('Add starts with an empty name', values.name === '', JSON.stringify(values.name));
        check('Add starts with an empty description', values.description === '',
            JSON.stringify(values.description));
        check('Add starts on Active', values.status === 'ACTIVE', values.status);
        check('Add posts to the create address', values.action === '/admin/topics', values.action);

        // And from a plain list, where the rendered dialog already IS the Add form, it opens in place.
        await page.goto(BASE + '/admin/topics', { waitUntil: 'networkidle0' });
        const listUrl = page.url();
        await page.click('[data-practice-dialog-for="new"]');
        await sleep(200);
        values = await dialogValues(page);

        check('Add from the list opens in place, with no navigation',
            page.url() === listUrl && values.modal, page.url());
        check('and it is still the empty Add form',
            values.title === 'Add Topic' && values.name === '', values.title);

        // ---------------------------------------------------------------- status, as colour
        //
        // Narrowed to this fixture's own topic. A shared development database has real topics in it,
        // and "the first row" is whichever of them sorts first — which is somebody else's data and a
        // different answer tomorrow.
        const onlyFixture = BASE + '/admin/topics?q=' + encodeURIComponent(fx.topicA.name);
        await page.goto(onlyFixture, { waitUntil: 'networkidle0' });
        const badges = await page.evaluate(() => {
            const row = document.querySelector('.practice-topics tbody tr');
            const badge = row.querySelector('.badge');

            return {
                classes: badge.className,
                colour: getComputedStyle(badge).backgroundColor,
                text: badge.textContent.trim(),
            };
        });

        check('Active is a success badge', badges.classes.includes('badge--success'), badges.classes);
        check('the badge is painted, not plain text',
            badges.colour !== 'rgba(0, 0, 0, 0)' && badges.colour !== 'transparent', badges.colour);

        const actions = await page.evaluate(() => {
            const cell = document.querySelector('.practice-topics tbody .practice-actions');

            return Array.from(cell.querySelectorAll('.btn')).map((b) => ({
                label: b.textContent.trim(),
                classes: b.className,
                disabled: b.disabled === true || b.getAttribute('aria-disabled') === 'true',
                opacity: getComputedStyle(b).opacity,
            }));
        });

        check('the row offers Edit, one status action and Delete', actions.length === 3,
            actions.map((a) => a.label).join(', '));
        check('Delete is the destructive one',
            actions.some((a) => a.label === 'Delete' && a.classes.includes('btn--danger')));
        check('Deactivate is the warning one',
            actions.some((a) => a.label === 'Deactivate' && a.classes.includes('btn--warning')));
        check('no action is drawn as disabled',
            actions.every((a) => !a.disabled && parseFloat(a.opacity) > 0.9),
            actions.map((a) => a.label + '@' + a.opacity).join(', '));
        check('Archive is offered nowhere', !actions.some((a) => a.label === 'Archive'));

        // Readable, measured. 4.5:1 is the WCAG AA threshold for ordinary text.
        const contrast = await actionContrast(page);
        const unreadable = Object.entries(contrast).filter(([, ratio]) => ratio < 4.5);

        check('every action label is readable against what is behind it', unreadable.length === 0,
            Object.entries(contrast).map(([l, r]) => l + ' ' + r + ':1').join(', '));

        // The other half of the pair: Activate, which only appears once a topic is inactive.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-actions button.btn--warning'),
        ]);
        await page.goto(onlyFixture, { waitUntil: 'networkidle0' });

        const activateContrast = await actionContrast(page);
        const badActivate = Object.entries(activateContrast).filter(([, ratio]) => ratio < 4.5);

        check('Activate is offered once the topic is inactive',
            Object.keys(activateContrast).includes('Activate'),
            Object.keys(activateContrast).join(', '));
        check('and its label is readable too', badActivate.length === 0,
            Object.entries(activateContrast).map(([l, r]) => l + ' ' + r + ':1').join(', '));

        // Put it back, so the checks below see the topic as the fixture seeded it.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-actions button.btn--success'),
        ]);
        await page.goto(onlyFixture, { waitUntil: 'networkidle0' });

        // ---------------------------------------------------------------- the details dialog, here too
        await page.click('.practice-topics [data-practice-topic-details]');
        await sleep(200);

        const listDetails = await page.evaluate(() => {
            const dialog = document.querySelector('#practice-topic-details-dialog');
            const orders = dialog.querySelector('[data-practice-details-orders]');

            return {
                modal: dialog.matches(':modal'),
                name: dialog.querySelector('[data-practice-details-name]').textContent.trim(),
                description: dialog.querySelector('[data-practice-details-description]').textContent.trim(),
                status: dialog.querySelector('[data-practice-details-status]').textContent.trim(),
                orders: orders.hidden ? null : orders.textContent.trim(),
                forms: dialog.querySelectorAll('form').length,
            };
        });

        check('the topic name opens the details dialog on Topic Management too', listDetails.modal);
        check('it shows this topic\'s description',
            listDetails.description === fx.topicA.description, listDetails.description);
        check('it shows the status', listDetails.status === 'Active', listDetails.status);
        check('it shows the assignment count this page already had',
            listDetails.orders === '1', String(listDetails.orders));
        check('and carries no form', listDetails.forms === 0, String(listDetails.forms));

        await page.click('#practice-topic-details-dialog [data-practice-dialog-close]');
        await sleep(150);

        // ---------------------------------------------------------------- the actions stay on one line
        const actionLayout = await page.evaluate(() => {
            const cell = document.querySelector('.practice-topics tbody .practice-actions');
            const tops = Array.from(cell.querySelectorAll('.btn')).map((b) => Math.round(
                b.getBoundingClientRect().top,
            ));

            return { tops, distinct: new Set(tops).size, height: Math.round(cell.getBoundingClientRect().height) };
        });

        check('the three actions share one line', actionLayout.distinct === 1,
            'tops: ' + actionLayout.tops.join(', '));

        // ---------------------------------------------------------------- layout, at three widths
        for (const width of [1920, 1440]) {
            await page.setViewport({ width, height: 1000 });
            await page.goto(onlyFixture, { waitUntil: 'networkidle0' });

            const lines = await headerLines(page);
            const wrapped = Object.entries(lines).filter(([, n]) => n !== 1).map(([l]) => l);

            check('every header is on one line at ' + width, wrapped.length === 0,
                wrapped.length === 0 ? JSON.stringify(lines) : 'wrapped: ' + wrapped.join(', '));
            check('the page does not scroll sideways at ' + width, !(await pageOverflows(page)));
        }

        await page.setViewport({ width: 390, height: 850 });
        await page.goto(onlyFixture, { waitUntil: 'networkidle0' });

        check('a phone-width page does not scroll sideways', !(await pageOverflows(page)));
        check('the table itself takes the overflow instead', await page.evaluate(() => {
            const scroller = document.querySelector('.practice-topics-scroll');

            return scroller !== null && getComputedStyle(scroller).overflowX === 'auto';
        }));

        // ---------------------------------------------------------------- Practice Orders
        await page.setViewport({ width: 1500, height: 1000 });
        const storeUrl = BASE + '/practice-orders/store/' + fx.store;
        await page.goto(storeUrl, { waitUntil: 'networkidle0' });

        const rowsOf = () => page.evaluate(
            () => Array.from(document.querySelectorAll('tbody [data-practice-select]')).map((b) => b.value),
        );

        const allRows = await rowsOf();
        check('both assignable orders are listed', allRows.length === 2, allRows.join(', '));
        check('the recording with no mirrored order offers no checkbox',
            !allRows.includes(String(fx.orphanOrder)), allRows.join(', '));
        check('and offers no Set Topic button',
            await page.$('[data-practice-set-topic="' + fx.orphanOrder + '"]') === null);

        // **The auto-apply regression.** Choose an option and fire the event a real select fires.
        const urlBefore = page.url();
        await page.select('.practice-filter select[name="topic"]', String(fx.topicA.id));
        await sleep(400);

        check('choosing a topic does not navigate', page.url() === urlBefore, page.url());
        check('and does not change the rows', (await rowsOf()).length === 2);
        check('the select is holding the new choice', await page.evaluate(
            () => document.querySelector('.practice-filter select[name="topic"]').value,
        ) === String(fx.topicA.id));

        // Apply is what filters.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter button[type="submit"]'),
        ]);

        check('Apply puts the filter in the address', page.url().includes('topic=' + fx.topicA.id), page.url());
        const filtered = await rowsOf();
        check('Apply narrows the list to that topic',
            filtered.length === 1 && filtered[0] === String(fx.assignedOrder), filtered.join(', '));

        // Clear takes it off again.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter a.btn'),
        ]);
        check('Clear returns to every order', (await rowsOf()).length === 2, page.url());

        // ---------------------------------------------------------------- the Set Topic column
        //
        // Rows in three different states — assigned, unassigned, and a recording with no source order
        // at all — must still produce one tidy column rather than three different shapes.
        const topicColumn = await page.evaluate(() => {
            const cells = Array.from(document.querySelectorAll('.practice-topic-cell'));

            return cells.map((cell) => {
                const btn = cell.querySelector('.btn');
                const stack = cell.querySelector('.practice-topic-cell__stack');
                const rect = cell.getBoundingClientRect();

                return {
                    hasStack: stack !== null,
                    button: btn === null ? null : btn.textContent.trim(),
                    // Where the button's left edge sits relative to its own cell.
                    buttonInset: btn === null ? null : Math.round(btn.getBoundingClientRect().left - rect.left),
                    // Does anything spill out of the column?
                    overflows: cell.scrollWidth > cell.clientWidth + 1,
                };
            });
        });

        check('every topic cell uses the one stack',
            topicColumn.every((c) => c.hasStack), JSON.stringify(topicColumn.map((c) => c.hasStack)));
        check('no topic cell overflows its column',
            topicColumn.every((c) => !c.overflows));

        const insets = topicColumn.filter((c) => c.button !== null).map((c) => c.buttonInset);
        check('every button starts at the same left edge', new Set(insets).size === 1,
            insets.join(', '));
        // Row order is by newest activity, so the states are compared as a set rather than a sequence.
        const states = topicColumn.map((c) => String(c.button)).sort().join('|');

        check('the three states are each present once', states === 'Change|Set Topic|null', states);

        // ---------------------------------------------------------------- the details dialog
        await page.click('.practice-topic-cell [data-practice-topic-details]');
        await sleep(200);

        const details = await page.evaluate(() => {
            const dialog = document.querySelector('#practice-topic-details-dialog');

            return {
                modal: dialog.matches(':modal'),
                name: dialog.querySelector('[data-practice-details-name]').textContent.trim(),
                description: dialog.querySelector('[data-practice-details-description]').textContent.trim(),
                status: dialog.querySelector('[data-practice-details-status]').textContent.trim(),
                statusClasses: dialog.querySelector('[data-practice-details-status]').className,
                forms: dialog.querySelectorAll('form').length,
                assignAlsoOpen: document.querySelector('#practice-assign-dialog').open,
            };
        });

        check('the topic name opens the details dialog', details.modal);
        check('it shows the topic name', details.name === fx.topicA.name, details.name);
        check('it shows the description', details.description === fx.topicA.description, details.description);
        check('it shows the real status', details.status === 'Active', details.status);
        check('the status is badged green', details.statusClasses.includes('badge--success'),
            details.statusClasses);
        check('it is read-only: no form in it', details.forms === 0, String(details.forms));
        check('and it did not open the assignment dialog', details.assignAlsoOpen === false);

        await page.click('#practice-topic-details-dialog [data-practice-dialog-close]');
        await sleep(150);

        // Change opens the other one — two controls, two purposes.
        await page.click('[data-practice-set-topic="' + fx.assignedOrder + '"]');
        await sleep(200);

        const afterChange = await page.evaluate(() => ({
            assign: document.querySelector('#practice-assign-dialog').matches(':modal'),
            details: document.querySelector('#practice-topic-details-dialog').open,
            preselected: document.querySelector('[data-practice-assign-topic]').value,
        }));

        check('Change opens the assignment dialog', afterChange.assign);
        check('and leaves the details dialog closed', afterChange.details === false);
        check('with the current topic preselected',
            afterChange.preselected === String(fx.topicA.id), afterChange.preselected);

        // ---------------------------------------------------------------- nothing broke quietly
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
