/* An explicit Caller or Callee recording is one person's words — on every screen that shows it. */
const { BASE, launch, signIn, selectTurnText, sleep } = require('./lib');
const fx = require('./fixtures.json');

const R = '.a2t-review-dialog';
let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function openDetailsFor(page, label) {
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    const button = await page.$('[data-a2t-details-label="' + label + '"]');
    if (!button) return false;
    await button.click();
    await page.waitForSelector(R + ' [data-a2t-turn]', { timeout: 5000 });
    await sleep(350);
    return true;
}

const labels = (page, root) => page.$$eval(root + ' .a2t-turn__who', (n) => n.map((x) => x.textContent.trim()));
const sides = (page, root) => page.$$eval(root + ' [data-a2t-turn]', (n) =>
    n.map((t) => (t.className.match(/a2t-turn--(left|right|neutral)/) || [])[1]));

async function oneSided(page, root, name, expectedSide) {
    const seen = await labels(page, root);
    check(name + ': every label is ' + name, seen.every((l) => l === name), JSON.stringify([...new Set(seen)]));
    check(name + ': no Speaker 1/2', !seen.some((l) => /^Speaker \d/.test(l)));
    check(name + ': no Agent/Customer', !seen.some((l) => l === 'Agent' || l === 'Customer'));
    const s = await sides(page, root);
    check(name + ': all on the ' + expectedSide, s.every((x) => x === expectedSide), JSON.stringify([...new Set(s)]));
}

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    for (const [label, side] of [['Caller', 'left'], ['Callee', 'right']]) {
        console.log('\n' + label.toUpperCase() + ': DETAILS');
        check('opened', await openDetailsFor(page, label));
        await oneSided(page, R, label, side);
        check(label + ': no confirm-roles button',
            !(await page.$$eval(R + ' button', (n) => n.some((b) => /Confirm speaker roles/.test(b.textContent))))); 
        check(label + ': no "could not tell which speaker" warning',
            !(await page.$eval(R, (d) => d.textContent)).includes('could not tell which speaker'));
        check(label + ': says what the recording is',
            (await page.$eval(R, (d) => d.textContent)).includes('side of the call'));
        check(label + ': no move control', (await page.$$(R + ' [data-a2t-move]')).length === 0);
        check(label + ': edit still offered', (await page.$$(R + ' [data-a2t-edit]')).length > 0);

        // Edit, then confirm the identity survives the refresh.
        await page.click(R + ' [data-a2t-turn="0"] [data-a2t-edit]');
        await sleep(250);
        await page.$eval(R + ' [data-a2t-turn="0"] [data-a2t-editor-text]', (t) => { t.value = 'VOICE E2E EDIT.'; });
        await page.click(R + ' [data-a2t-turn="0"] [data-a2t-edit-save]');
        await sleep(1300);
        check(label + ': edit saved', (await page.$$eval(R + ' [data-a2t-text]', (n) => n[0].textContent.trim()))
            === 'VOICE E2E EDIT.');
        await oneSided(page, R, label, side);
        check(label + ': history icon after edit', (await page.$$(R + ' [data-a2t-history]')).length > 0);
        await page.click(R + ' [data-a2t-turn="0"] [data-a2t-history]');
        await sleep(500);
        const hist = await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.textContent);
        check(label + ': revision labelled ' + label, hist.includes(label));
        check(label + ': revision shows no Speaker 1/2', !/Speaker \d/.test(hist));
        await page.click('[data-a2t-history-dialog="0"] [data-a2t-history-close]');
        await sleep(250);

        // Selection and merge stay available.
        await selectTurnText(page, R, 2);
        await sleep(300);
        check(label + ': selection still works', (await page.$$(R + ' .a2t-turn--selected')).length === 1);
        const chip = await page.$(R + ' [data-a2t-merge-controls]:not([hidden]) [data-a2t-merge-with="previous"]');
        check(label + ': "With previous" offered', !!chip);
        if (chip) {
            const n = (await page.$$(R + ' [data-a2t-turn]')).length;
            await chip.click();
            await sleep(400);
            check(label + ': merge confirm opened', await page.$eval('[data-a2t-merge-dialog]', (d) => d.open));
            await page.click('[data-a2t-merge-confirm]');
            await sleep(1400);
            check(label + ': merged', (await page.$$(R + ' [data-a2t-turn]')).length === n - 1);
            await oneSided(page, R, label, side);
        }

        console.log('\n' + label.toUpperCase() + ': ORIGINAL TRANSCRIPT');
        await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
        const buttons = await page.$$('[data-a2t-transcripts]');
        let found = false;
        for (const t of buttons) {
            await t.click();
            // Wait for the render rather than guessing at it: a fixed sleep read an empty body.
            await page.waitForSelector('.a2t-transcript-dialog .a2t-turn__who', { timeout: 5000 });
            // One order holds all three recordings, so the dialog has a tab each. Pick this one's.
            await page.evaluate((label) => {
                const tab = [...document.querySelectorAll('[data-a2t-transcript-tabs] .a2t-tab')]
                    .find((b) => b.textContent.trim() === label);
                if (tab) tab.click();
            }, label);
            await sleep(300);
            const meta = await page.$eval('[data-a2t-transcript-meta]', (n) => n.textContent);
            if (meta.startsWith(label)) {
                const seen = await page.$$eval('.a2t-transcript-dialog .a2t-turn__who',
                    (n) => n.map((x) => x.textContent.trim()));
                check(label + ': original transcript is ' + label + '-only',
                    seen.length > 0 && seen.every((l) => l === label), JSON.stringify([...new Set(seen)]));
                const tside = await page.$$eval('.a2t-transcript-dialog [class*="a2t-turn--"]',
                    (n) => [...new Set(n.map((t) => (t.className.match(/a2t-turn--(left|right|neutral)/) || [])[1]))]);
                check(label + ': original transcript is one-sided', tside.length === 1, JSON.stringify(tside));
                found = true;
            }
            await page.evaluate(() => {
                const d = document.querySelector('.a2t-transcript-dialog');
                if (d && d.open) d.close();
            });
            await sleep(200);
            if (found) break;
        }
        check(label + ': original transcript located', found);
    }

    console.log('\nMIXED: UNCHANGED');
    check('opened', await openDetailsFor(page, 'Common / Mixed'));
    const mixedLabels = await labels(page, R);
    check('mixed keeps two speakers',
        new Set(mixedLabels).size === 2, JSON.stringify([...new Set(mixedLabels)]));
    check('mixed alternates sides', new Set(await sides(page, R)).size === 2);
    check('mixed still offers move', (await page.$$(R + ' [data-a2t-move]')).length > 0);

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS FAILED:', e.stack); process.exit(2); });
