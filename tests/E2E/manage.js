/* Manage Audio: replacing one recording of an order, in a real browser. */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures.json');
const { execFileSync } = require('child_process');
const path = require('path');

const D = '.a2t-manage-dialog';
const ORDER = '99001122';
let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** The row of the store table whose Order ID cell names our order. */
async function orderRow(page) {
    return page.evaluate((order) => {
        const row = [...document.querySelectorAll('.a2t-orders tbody tr')]
            .find((r) => r.textContent.includes(order));
        return row ? row.textContent.replace(/\s+/g, ' ').trim() : null;
    }, ORDER);
}

async function openManage(page) {
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    const opened = await page.evaluate((order) => {
        const row = [...document.querySelectorAll('.a2t-orders tbody tr')]
            .find((r) => r.textContent.includes(order));
        const button = row && row.querySelector('[data-a2t-manage]');
        if (!button) return false;
        button.click();
        return true;
    }, ORDER);
    if (!opened) return false;
    await page.waitForSelector(D + ' .a2t-manage__slot', { timeout: 5000 });
    await sleep(250);
    return true;
}

const slots = (page) => page.$$eval(D + ' .a2t-manage__slot', (n) => n.map((s) => ({
    title: s.querySelector('.a2t-manage__title').textContent.trim(),
    versions: [...s.querySelectorAll('.a2t-manage__version')].map((v) => ({
        num: v.querySelector('.a2t-manage__vnum').textContent.trim(),
        state: v.querySelector('.a2t-manage__vstate').textContent.trim(),
        warn: v.querySelector('.a2t-manage__vwarn') ? v.querySelector('.a2t-manage__vwarn').textContent.trim() : null,
        meta: v.querySelector('.a2t-manage__vmeta').textContent.trim(),
    })),
})));

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    console.log('\nA. THE DIALOG');
    check('manage opens for the order', await openManage(page));
    let found = await slots(page);
    check('one section per recording kind',
        JSON.stringify(found.map((s) => s.title)) === JSON.stringify(['Common / Mixed', 'Caller', 'Callee']),
        JSON.stringify(found.map((s) => s.title)));
    check('each holds one current version',
        found.every((s) => s.versions.length === 1 && s.versions[0].state === 'Current'),
        JSON.stringify(found.map((s) => s.versions.map((v) => v.num + ' ' + v.state))));

    console.log('\nB. UPLOADING A REPLACEMENT FOR THE CALLER SIDE ONLY');
    // The Replace button of the Caller section, then its own form's file input.
    await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        slot.querySelector('[data-a2t-replace]').click();
    });
    await sleep(200);
    const formVisible = await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        return !slot.querySelector('[data-a2t-replace-form]').hidden;
    });
    check('Replace reveals the upload, in its own section', formVisible);

    // The two upload options, as rendered.
    const fields = await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        const form = slot.querySelector('[data-a2t-replace-form]');
        const box = form.querySelector('input[name=generate_ai_audio]');
        return {
            labels: [...form.querySelectorAll('.field__label')].map((l) => l.textContent.trim()),
            options: [...form.querySelectorAll('select[name=transcription_provider] option')]
                .map((o) => ({ value: o.value, selected: o.selected, disabled: o.disabled })),
            checked: box.checked,
            boxLabel: form.querySelector('.a2t-checkbox span').textContent.trim(),
        };
    });
    check('the form offers the audio file and the provider',
        JSON.stringify(fields.labels) === JSON.stringify(['Audio file', 'Transcription provider']),
        JSON.stringify(fields.labels));
    check('every provider is listed', fields.options.length >= 2, JSON.stringify(fields.options));
    check('it starts on the provider of the recording being replaced',
        fields.options.some((o) => o.selected), JSON.stringify(fields.options));
    check('an unrunnable provider is offered disabled, not hidden',
        fields.options.every((o) => o.disabled === false || o.disabled === true));
    check('the paid box is offered', fields.boxLabel === 'Generate clean AI audio after transcription',
        fields.boxLabel);
    check('and starts unticked', fields.checked === false);

    // Cancel and reopen: the paid box must come back unticked even after being ticked.
    await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        const form = slot.querySelector('[data-a2t-replace-form]');
        form.querySelector('input[name=generate_ai_audio]').checked = true;
        form.querySelector('[data-a2t-replace-cancel]').click();
    });
    await sleep(200);
    await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        slot.querySelector('[data-a2t-replace]').click();
    });
    await sleep(200);
    check('cancel and reopen leaves the paid box unticked', await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        const form = slot.querySelector('[data-a2t-replace-form]');
        return form.querySelector('input[name=generate_ai_audio]').checked === false
            && form.querySelector('input[type=file]').value === '';
    }));

    const input = await page.$('.a2t-manage__slot:nth-of-type(2) input[type=file]');
    await input.uploadFile(path.join(__dirname, '..', '_data', 'recording-channels', '22342359-caller.wav'));
    await page.evaluate(() => {
        const slot = [...document.querySelectorAll('.a2t-manage__slot')]
            .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
        slot.querySelector('[data-a2t-replace-form] button[type=submit]').click();
    });
    await page.waitForFunction(
        () => !document.querySelector('.a2t-manage-dialog .source-modal__status').hidden
            && /replacement/i.test(document.querySelector('.a2t-manage-dialog .source-modal__status').textContent),
        { timeout: 10000 },
    );
    await sleep(600);

    found = await slots(page);
    const caller = found.find((s) => s.title === 'Caller');
    check('the caller side now has two versions', caller.versions.length === 2,
        JSON.stringify(caller.versions.map((v) => v.num + ' ' + v.state)));
    check('the replacement is reported as in progress, not as history',
        caller.versions[0].warn === 'Replacement in progress',
        JSON.stringify(caller.versions[0]));
    check('and the recording it replaces is still current',
        caller.versions[1].state === 'Current' && caller.versions[1].num === 'v1',
        JSON.stringify(caller.versions[1]));
    const others = found.filter((s) => s.title !== 'Caller');
    check('the other two recordings are untouched',
        others.every((s) => s.versions.length === 1 && s.versions[0].state === 'Current'),
        JSON.stringify(others.map((s) => s.title + ':' + s.versions.length)));

    console.log('\nC. THE STORE ROW WHILE THE REPLACEMENT IS PROCESSING');
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    let row = await orderRow(page);
    check('the row still reports the order as completed', /Completed/.test(row), row.slice(0, 120));
    check('and still offers the caller recording to play', /0:1\d/.test(row), row.slice(0, 120));

    console.log('\nD. WHEN THE REPLACEMENT FINISHES');
    execFileSync('php', [path.join(__dirname, 'complete-replacement.php'), String(fx.store), ORDER, 'CALLER'], {
        stdio: 'pipe',
    });
    check('manage reopens', await openManage(page));
    found = await slots(page);
    const after = found.find((s) => s.title === 'Caller');
    check('the replacement is now current',
        after.versions[0].state === 'Current' && after.versions[0].num === 'v2',
        JSON.stringify(after.versions.map((v) => v.num + ' ' + v.state)));
    check('and the one it replaced is kept as superseded',
        after.versions[1].state === 'Superseded' && after.versions[1].num === 'v1',
        JSON.stringify(after.versions[1]));
    check('the superseded recording is still openable',
        await page.evaluate(() => {
            const slot = [...document.querySelectorAll('.a2t-manage__slot')]
                .find((s) => s.querySelector('.a2t-manage__title').textContent.trim() === 'Caller');
            const old = slot.querySelectorAll('.a2t-manage__version')[1];
            return [...old.querySelectorAll('a')].map((a) => a.textContent.trim()).join(',');
        }));

    console.log('\nE. THE TRANSCRIPT THE ORDER NOW SHOWS IS THE REPLACEMENT S');
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    const opened = await page.evaluate((order) => {
        const r = [...document.querySelectorAll('.a2t-orders tbody tr')].find((x) => x.textContent.includes(order));
        // The Caller cell's Details button.
        const button = [...r.querySelectorAll('[data-a2t-details-label]')]
            .find((b) => b.getAttribute('data-a2t-details-label') === 'Caller');
        if (!button) return false;
        button.click();
        return true;
    }, ORDER);
    check('the caller details open', opened);
    if (opened) {
        await page.waitForSelector('.a2t-review-dialog [data-a2t-turn]', { timeout: 5000 });
        const spoken = await page.$$eval('.a2t-review-dialog [data-a2t-text]', (n) =>
            n.map((x) => x.textContent.trim()));
        check('and show the replacement transcript, not the replaced one',
            spoken.some((t) => /replacement recording/i.test(t)),
            JSON.stringify(spoken.slice(0, 2)));
    }

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})();
