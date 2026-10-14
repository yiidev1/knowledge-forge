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

        // ---------------------------------------------------------------- Order Testing: assigning
        await page.setViewport({ width: 1500, height: 1000 });
        const otUrl = BASE + '/order-testing/store/' + fx.store;
        await page.goto(otUrl, { waitUntil: 'networkidle0' });

        const rowsOf = () => page.evaluate(
            () => Array.from(document.querySelectorAll('tbody [data-practice-select]')).map((b) => b.value),
        );

        check('the management table lists the practisable orders',
            (await rowsOf()).length === 2, (await rowsOf()).join(', '));
        check('and still lists the one with no mirrored order', await page.evaluate(
            (orphan) => document.body.textContent.indexOf(String(orphan)) !== -1,
            fx.orphanOrder,
        ));
        check('which offers no control of its own',
            await page.$('#practice-topic-' + fx.orphanOrder) === null);

        const selectFor = (order) => '#practice-topic-' + order;

        // The inline Save is removed once the script runs, so the confirmation is the only path.
        check('the inline Save is withdrawn when the script is running', await page.evaluate(
            () => {
                const save = document.querySelector('[data-practice-assign-save]');

                return save !== null && save.hidden === true;
            },
        ));

        // **Choosing does not save.** Pick a topic and wait; nothing may be written.
        await page.select(selectFor(fx.assignedOrder), String(fx.topicB.id));
        await sleep(400);

        const confirmState = await page.evaluate(() => {
            const dialog = document.querySelector('#practice-confirm-dialog');

            return {
                open: dialog.matches(':modal'),
                order: dialog.querySelector('[data-practice-confirm-order]').textContent.trim(),
                current: dialog.querySelector('[data-practice-confirm-current]').textContent.trim(),
                next: dialog.querySelector('[data-practice-confirm-next]').textContent.trim(),
            };
        });

        check('choosing a topic opens a confirmation', confirmState.open);
        check('it names the order', confirmState.order === '#' + fx.assignedOrder, confirmState.order);
        check('it shows the current topic', confirmState.current === fx.topicA.name, confirmState.current);
        check('it shows the new topic', confirmState.next === fx.topicB.name, confirmState.next);
        check('and nothing has navigated', page.url() === otUrl, page.url());

        // Cancel: nothing written, and the control goes back to what is stored.
        await page.click('#practice-confirm-dialog [data-practice-dialog-close]');
        await sleep(300);

        check('Cancel restores the stored selection', await page.evaluate(
            (sel, saved) => document.querySelector(sel).value === String(saved),
            selectFor(fx.assignedOrder), fx.topicA.id,
        ));
        check('and saved nothing', page.url() === otUrl, page.url());

        // Confirm: this one writes.
        await page.select(selectFor(fx.assignedOrder), String(fx.topicB.id));
        await sleep(300);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('[data-practice-confirm-submit]'),
        ]);

        check('Confirm saves and returns to the list', page.url().indexOf('/order-testing/store/') !== -1,
            page.url());
        check('the saved topic is what the control now shows', await page.evaluate(
            (sel, expected) => document.querySelector(sel).value === String(expected),
            selectFor(fx.assignedOrder), fx.topicB.id,
        ));

        // ---------------------------------------------------------------- the multi-topic filter
        await page.goto(otUrl, { waitUntil: 'networkidle0' });

        const beforeFilter = (await rowsOf()).length;

        await page.click('[data-practice-filter-picker] summary');
        await sleep(200);
        await page.evaluate((id) => {
            document.querySelector('.practice-filter input[value="' + id + '"]').click();
        }, fx.topicB.id);
        await sleep(400);

        check('ticking a topic does not filter anything yet',
            (await rowsOf()).length === beforeFilter && page.url() === otUrl, page.url());
        check('the summary counts what is ticked', await page.evaluate(
            () => document.querySelector('[data-practice-filter-summary]').textContent.trim(),
        ) === '1 selected');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter button[type="submit"]'),
        ]);

        check('Apply puts the selection in the address',
            page.url().indexOf('topics') !== -1, page.url());
        check('and narrows the list', (await rowsOf()).length === 1, (await rowsOf()).join(', '));
        check('the ticked box comes back ticked', await page.evaluate(
            (id) => document.querySelector('.practice-filter input[value="' + id + '"]').checked,
            fx.topicB.id,
        ));

        // Two at once, OR across them.
        await page.click('[data-practice-filter-picker] summary');
        await sleep(200);
        await page.evaluate(() => {
            document.querySelector('.practice-filter input[value="none"]').click();
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter button[type="submit"]'),
        ]);

        check('a topic OR unassigned matches both', (await rowsOf()).length === 2,
            (await rowsOf()).join(', '));

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter a.btn'),
        ]);
        check('Clear removes every restriction', page.url() === otUrl, page.url());

        // ---------------------------------------------------------------- Practice Orders, read-only
        const poUrl = BASE + '/practice-orders/store/' + fx.store;
        await page.goto(poUrl, { waitUntil: 'networkidle0' });

        const readOnly = await page.evaluate(() => ({
            selects: document.querySelectorAll('[data-practice-assign-select]').length,
            checkboxes: document.querySelectorAll('[data-practice-select]').length,
            bulk: document.querySelectorAll('#practice-bulk-form').length,
            upload: document.querySelectorAll('[data-a2t-open="a2t-upload-dialog"]').length,
            manage: document.querySelectorAll('#a2t-manage-dialog').length,
            fullEditor: document.querySelectorAll('[data-a2t-full-editor]').length,
            filter: document.querySelectorAll('.practice-filter').length,
            topicNames: document.querySelectorAll('[data-practice-topic-details]').length,
            players: document.querySelectorAll('[data-a2t-play]').length,
        }));

        check('Practice Orders offers no assignment control',
            readOnly.selects === 0 && readOnly.checkboxes === 0 && readOnly.bulk === 0,
            JSON.stringify(readOnly));
        check('and no audio management',
            readOnly.upload === 0 && readOnly.manage === 0 && readOnly.fullEditor === 0,
            JSON.stringify(readOnly));
        check('while keeping the filter, the topic names and the players',
            readOnly.filter === 1 && readOnly.topicNames > 0 && readOnly.players > 0,
            JSON.stringify(readOnly));

        // ---------------------------------------------------------------- the details dialog
        await page.click('.practice-topic-cell [data-practice-topic-details]');
        await sleep(200);

        const details = await page.evaluate(() => {
            const dialog = document.querySelector('#practice-topic-details-dialog');

            return {
                modal: dialog.matches(':modal'),
                name: dialog.querySelector('[data-practice-details-name]').textContent.trim(),
                forms: dialog.querySelectorAll('form').length,
            };
        });

        check('the topic name still opens its details', details.modal);
        check('read-only: no form in it', details.forms === 0, String(details.forms));
        check('and it names a topic', details.name !== '', details.name);

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
