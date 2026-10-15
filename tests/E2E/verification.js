/*
 * Verifying an order, in a real browser.
 *
 * What only this can show: that choosing does not save, that confirming saves without navigating, and
 * that the table cell and the conversation dialog's button are two views of one piece of state rather
 * than two pieces of state that happen to agree at first.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/verification.js
 */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures-topics.json');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const CELL = (order) => '[data-ot-verify][data-ot-order="' + order + '"]';

/** What one row's control currently says, read from the DOM rather than from what we expect. */
const cellState = (page, order) => page.evaluate((selector) => {
    const form = document.querySelector(selector);

    if (form === null) {
        return { present: false };
    }

    const button = form.querySelector('[data-ot-verify-button]');

    return {
        present: true,
        verified: form.getAttribute('data-ot-verified') === '1',
        version: form.querySelector('[data-ot-version]').value,
        action: form.querySelector('[data-ot-decision]').value,
        label: (button.textContent || '').replace(/\s+/g, ' ').trim(),
        isBadge: button.className.indexOf('ot-verify__badge') !== -1,
    };
}, CELL(order));

async function run() {
    const { browser, page, errors } = await launch();
    const order = fx.freeOrder;

    try {
        await signIn(page, fx);

        const url = BASE + '/order-testing/store/' + fx.store;
        const posts = [];
        page.on('request', (r) => {
            if (r.method() === 'POST') { posts.push(new URL(r.url()).pathname); }
        });

        await page.goto(url, { waitUntil: 'networkidle0' });

        // ---------------------------------------------------------------- what the page offers
        check('the table carries a Verification column', await page.evaluate(
            () => Array.from(document.querySelectorAll('thead th'))
                .some((th) => th.textContent.trim() === 'Verification'),
        ));

        const before = await cellState(page, order);
        check('an untouched order offers Verify', before.present && before.verified === false
            && before.label === 'Verify', JSON.stringify(before));

        check('a row with no mirrored source order offers nothing', await page.evaluate(
            (selector) => document.querySelector(selector) === null,
            CELL(fx.orphanOrder),
        ));

        // ---------------------------------------------------------------- choosing does not save
        await page.click(CELL(order) + ' [data-ot-verify-button]');
        await sleep(400);

        const dialog = await page.evaluate(() => {
            const el = document.querySelector('#ot-verify-dialog');

            return {
                modal: el.matches(':modal'),
                title: el.querySelector('[data-ot-verify-title]').textContent.trim(),
                order: el.querySelector('[data-ot-verify-order]').textContent.trim(),
                message: el.querySelector('[data-ot-verify-message]').textContent.trim(),
                confirm: el.querySelector('[data-ot-verify-confirm]').textContent.trim(),
            };
        });

        check('pressing Verify opens a confirmation', dialog.modal, JSON.stringify(dialog));
        check('it is titled Verify Order', dialog.title === 'Verify Order', dialog.title);
        check('it names the order', dialog.order === '#' + order, dialog.order);
        check('it asks whether the order was reviewed',
            /fully reviewed and checked/.test(dialog.message), dialog.message);
        check('and nothing has been sent', posts.length === 0, posts.join(' '));

        // Cancel: still nothing sent, and the row is untouched.
        await page.click('#ot-verify-dialog [data-practice-dialog-close]');
        await sleep(300);

        check('Cancel sends nothing', posts.length === 0, posts.join(' '));
        check('and leaves the row alone', (await cellState(page, order)).verified === false);

        // ---------------------------------------------------------------- confirming saves
        const was = page.url();

        await page.click(CELL(order) + ' [data-ot-verify-button]');
        await sleep(300);
        await page.click('#ot-verify-dialog [data-ot-verify-confirm]');
        await sleep(900);

        const after = await cellState(page, order);

        check('confirming sends exactly one request', posts.length === 1, posts.join(' '));
        check('to this order\'s own address',
            posts[0] === '/order-testing/store/' + fx.store + '/order/' + order + '/verification',
            posts[0]);
        check('the row becomes a verified badge',
            after.verified === true && after.isBadge === true && /Verified/.test(after.label),
            JSON.stringify(after));
        check('its version advanced', Number(after.version) > Number(before.version),
            before.version + ' -> ' + after.version);
        check('and the control now posts the other way', after.action === 'unverified', after.action);
        check('without navigating', page.url() === was, page.url());

        check('and it says so', await page.evaluate(() => {
            const notice = document.querySelector('[data-a2t-notice]');

            return notice !== null && notice.hidden === false
                && /verified successfully/.test(notice.textContent);
        }));

        // ---------------------------------------------------------------- it is really stored
        await page.goto(url, { waitUntil: 'networkidle0' });
        const reloaded = await cellState(page, order);

        check('a reload still shows it verified', reloaded.verified === true, JSON.stringify(reloaded));

        // ---------------------------------------------------------------- the conversation dialog
        await page.click('tr:has(' + CELL(order) + ') [data-a2t-order-open]');
        await sleep(1500);

        const inDialog = await page.evaluate(() => {
            const button = document.querySelector('[data-ot-verify-dialog]');

            return {
                present: button !== null,
                hidden: button === null ? null : button.hidden,
                label: button === null ? '' : (button.textContent || '').replace(/\s+/g, ' ').trim(),
            };
        });

        check('the dialog shows the same state', inDialog.present && inDialog.hidden === false
            && /Verified/.test(inDialog.label), JSON.stringify(inDialog));

        // Unverify from inside the dialog, and watch the table follow.
        await page.click('[data-ot-verify-dialog]');
        await sleep(400);

        const unverifyDialog = await page.evaluate(() => ({
            title: document.querySelector('[data-ot-verify-title]').textContent.trim(),
            confirm: document.querySelector('[data-ot-verify-confirm]').textContent.trim(),
            message: document.querySelector('[data-ot-verify-message]').textContent.trim(),
        }));

        check('the dialog control confirms too', unverifyDialog.title === 'Unverify Order',
            unverifyDialog.title);
        check('with the removal wording',
            /remove its verification status/.test(unverifyDialog.message), unverifyDialog.message);
        check('and a matching button', unverifyDialog.confirm === 'Confirm Unverify',
            unverifyDialog.confirm);

        await page.click('[data-ot-verify-confirm]');
        await sleep(900);

        const synced = await cellState(page, order);

        check('confirming in the dialog updates the table row',
            synced.verified === false && synced.label === 'Verify', JSON.stringify(synced));
        check('and the dialog button follows it', await page.evaluate(() => {
            const button = document.querySelector('[data-ot-verify-dialog]');

            return button !== null && /Verify/.test(button.textContent) && !/Verified/.test(button.textContent);
        }));

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
