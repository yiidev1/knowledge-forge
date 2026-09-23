/* The full correction page, after the shared-module refactor. Nothing here may have changed. */
const { BASE, launch, signIn, selectTurnText, sleep } = require('./lib');
const fx = require('./fixtures.json');

const P = '[data-a2t-review]';
let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const reviewUrl = BASE + '/audio-to-text/job/' + fx.job + '/review';
const turnTexts = (page) => page.$$eval(P + ' [data-a2t-text]', (n) => n.map((x) => x.textContent.trim()));

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    console.log('\nPAGE: LOADS AND ENHANCES');
    await page.goto(reviewUrl, { waitUntil: 'networkidle0' });
    check('review root found', !!(await page.$(P)));
    check('tools revealed by the script', await page.$eval('[data-a2t-tools]', (t) => !t.hidden));
    check('grip present', !!(await page.$('[data-a2t-grip]')));
    check('pencil present', !!(await page.$('[data-a2t-edit]')));

    console.log('\nPAGE: EDIT');
    const before = await turnTexts(page);
    await page.click('[data-a2t-turn="0"] [data-a2t-edit]');
    await sleep(250);
    check('inline editor opened', await page.$eval('[data-a2t-turn="0"] [data-a2t-editor]', (f) => !f.hidden));
    await page.$eval('[data-a2t-turn="0"] [data-a2t-editor-text]', (t) => { t.value = 'PAGE EDIT E2E.'; });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-a2t-turn="0"] [data-a2t-edit-save]'),
    ]);
    const afterEdit = await turnTexts(page);
    check('saved and redirected', afterEdit[0] === 'PAGE EDIT E2E.', afterEdit[0]);
    check('flash shown', (await page.content()).includes('Wording corrected'));

    console.log('\nPAGE: HISTORY');
    check('history icon on the edited turn', !!(await page.$('[data-a2t-turn="0"] [data-a2t-history]')));
    await page.click('[data-a2t-turn="0"] [data-a2t-history]');
    await sleep(400);
    check('history dialog open', await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.open));
    const hist = await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.textContent);
    check('shows Before and After', hist.includes('Before') && hist.includes('After'));
    check('shows the new wording', hist.includes('PAGE EDIT E2E.'));
    await page.click('[data-a2t-history-dialog="0"] [data-a2t-history-close]');
    await sleep(250);
    check('history closes', !(await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.open)));

    console.log('\nPAGE: SELECTION AND MERGE');
    await selectTurnText(page, P, 3);
    await sleep(300);
    check('one turn selected', (await page.$$(P + ' .a2t-turn--selected')).length === 1);
    check('its merge strip visible', await page.$eval(
        '[data-a2t-turn="3"] [data-a2t-merge-controls]', (c) => !c.hidden));
    await page.click('[data-a2t-turn="3"] [data-a2t-merge-with="previous"]');
    await sleep(400);
    check('merge confirm opened', await page.$eval('[data-a2t-merge-dialog]', (d) => d.open));
    check('preview filled', (await page.$eval('[data-a2t-merge-result]', (n) => n.textContent)).length > 0);
    await page.click('[data-a2t-merge-cancel]');
    await sleep(250);
    check('cancel closes it', !(await page.$eval('[data-a2t-merge-dialog]', (d) => d.open)));

    const beforeMerge = await turnTexts(page);
    await selectTurnText(page, P, 3);
    await sleep(300);
    await page.click('[data-a2t-turn="3"] [data-a2t-merge-with="previous"]');
    await sleep(300);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-a2t-merge-confirm]'),
    ]);
    check('merge applied', (await turnTexts(page)).length === beforeMerge.length - 1,
        beforeMerge.length + ' -> ' + (await turnTexts(page)).length);

    console.log('\nPAGE: DRAG TO MOVE');
    const roles = () => page.$$eval('[data-a2t-turn]', (n) => n.map((t) => t.getAttribute('data-a2t-role')));
    const beforeRoles = await roles();
    const grip = await page.$('[data-a2t-turn="1"] [data-a2t-grip]');
    const box = await grip.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    // Past the 5px threshold, then across to the opposite lane's drop band.
    await page.mouse.move(box.x + 40, box.y + 10, { steps: 6 });
    await sleep(200);
    const zone = await page.$('.a2t-dropzone');
    check('drop band appeared', !!zone);
    if (zone) {
        const zb = await zone.boundingBox();
        await page.mouse.move(zb.x + zb.width / 2, zb.y + zb.height / 2, { steps: 10 });
        await sleep(200);
        await page.mouse.up();
        await sleep(400);
        check('move confirm opened', await page.$eval('[data-a2t-move-dialog]', (d) => d.open));
        const to = await page.$eval('[data-a2t-move-to]', (n) => n.textContent);
        check('names a target speaker', to === 'Agent' || to === 'Customer', to);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('[data-a2t-move-confirm]'),
        ]);
        check('roles changed', JSON.stringify(await roles()) !== JSON.stringify(beforeRoles),
            JSON.stringify(beforeRoles) + ' -> ' + JSON.stringify(await roles()));
    } else {
        await page.mouse.up();
    }

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS FAILED:', e.stack); process.exit(2); });
