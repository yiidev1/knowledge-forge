/* The full operations, end to end: does Confirm actually change the transcript? */
const { BASE, launch, signIn, selectTurnText, sleep } = require('./lib');
const fx = require('./fixtures.json');

const R = '.a2t-review-dialog';
let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function openDetails(page) {
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    // The mixed recording specifically: it is the one with two speakers, so it is the one that offers
    // every control. Caller and Callee are covered by voices.js, where their absence is the point.
    await page.click('[data-a2t-details-label="Common / Mixed"]');
    await page.waitForSelector(R + ' [data-a2t-turn]', { timeout: 5000 });
    await sleep(300);
}

const turnTexts = (page) => page.$$eval(R + ' [data-a2t-text]', (n) => n.map((x) => x.textContent.trim()));
const version = (page) => page.$eval(R + ' [data-a2t-review-meta]', (n) => n.textContent);

(async () => {
    const { browser, page, errors } = await launch();
    await signIn(page, fx);

    /* ---------- A. EDIT ---------- */
    console.log('\nA. EDIT');
    await openDetails(page);
    const before = await turnTexts(page);
    await page.click(R + ' [data-a2t-turn="0"] [data-a2t-edit]');
    await sleep(250);
    check('editor opened', await page.$eval(R + ' [data-a2t-turn="0"] [data-a2t-editor]', (f) => !f.hidden));
    await page.$eval(R + ' [data-a2t-turn="0"] [data-a2t-editor-text]', (t) => { t.value = 'EDITED BY E2E.'; });
    await page.click(R + ' [data-a2t-turn="0"] [data-a2t-edit-save]');
    await sleep(1200);
    check('modal still open', await page.$eval(R, (d) => d.open));
    const afterEdit = await turnTexts(page);
    check('text changed', afterEdit[0] === 'EDITED BY E2E.', afterEdit[0]);
    check('version bumped', (await version(page)).includes('Version 1'), await version(page));

    /* ---------- B. HISTORY ---------- */
    console.log('\nB. HISTORY');
    const histIcons = (await page.$$(R + ' [data-a2t-history]')).length;
    check('history icon appears on the edited turn', histIcons > 0, histIcons + ' icon(s)');
    if (histIcons > 0) {
        await page.click(R + ' [data-a2t-turn="0"] [data-a2t-history]');
        await sleep(600);
        const open = await page.$$eval('dialog[data-a2t-history-dialog]', (d) => d.filter((x) => x.open).length);
        check('history dialog opened above Details', open === 1, open + ' open');
        check('history dialog is modal', await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.matches(':modal')));
        check('Details still open', await page.$eval(R, (d) => d.open));
        const text = await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.textContent);
        check('shows the same title as the page', text.includes('What was corrected'));
        check('shows Before and After', text.includes('Before') && text.includes('After'));
        check('shows the old wording', text.includes('Hi, can I get a large pepperoni?'));
        check('shows the new wording', text.includes('EDITED BY E2E.'));
        check('names the administrator', text.includes(fx.admin));
        await page.click('[data-a2t-history-dialog="0"] [data-a2t-history-close]');
        await sleep(300);
        check('history closes, Details stays', !(await page.$eval('[data-a2t-history-dialog="0"]', (d) => d.open))
            && (await page.$eval(R, (d) => d.open)));
        check('no history icon on an untouched turn',
            !(await page.$(R + ' [data-a2t-turn="1"] [data-a2t-history]')));
    }

    /* ---------- C. SELECTION ---------- */
    console.log('\nC. SELECTION');
    await openDetails(page);
    await selectTurnText(page, R, 2);
    await sleep(300);
    check('exactly one turn selected', (await page.$$(R + ' .a2t-turn--selected')).length === 1);
    check('exactly one merge strip visible', (await page.$$eval(
        R + ' [data-a2t-merge-controls]', (n) => n.filter((x) => !x.hidden).length)) === 1);
    await selectTurnText(page, R, 4);
    await sleep(300);
    check('selection moved to the other turn', await page.$eval(
        R + ' [data-a2t-turn="4"]', (t) => t.classList.contains('a2t-turn--selected')));
    check('first turn deselected', !(await page.$eval(
        R + ' [data-a2t-turn="2"]', (t) => t.classList.contains('a2t-turn--selected'))));

    /* ---------- D. WITH PREVIOUS ---------- */
    console.log('\nD. WITH PREVIOUS');
    await openDetails(page);
    const beforeMerge = await turnTexts(page);
    await selectTurnText(page, R, 3);
    await sleep(300);
    await page.click(R + ' [data-a2t-merge-controls]:not([hidden]) [data-a2t-merge-with="previous"]');
    await sleep(500);
    check('merge confirm opened', await page.$eval('[data-a2t-merge-dialog]', (d) => d.open));
    const preview = await page.$eval('[data-a2t-merge-dialog]', (d) => ({
        first: d.querySelector('[data-a2t-merge-first]').textContent,
        second: d.querySelector('[data-a2t-merge-second]').textContent,
    }));
    check('preview First is turn 2', preview.first === beforeMerge[2], preview.first);
    check('preview Second is turn 3', preview.second === beforeMerge[3], preview.second);
    await page.click('[data-a2t-merge-cancel]');
    await sleep(300);
    check('Cancel closes confirm, Details stays', !(await page.$eval('[data-a2t-merge-dialog]', (d) => d.open))
        && (await page.$eval(R, (d) => d.open)));
    check('nothing merged after cancel', (await turnTexts(page)).length === beforeMerge.length);

    await selectTurnText(page, R, 3);
    await sleep(300);
    await page.click(R + ' [data-a2t-merge-controls]:not([hidden]) [data-a2t-merge-with="previous"]');
    await sleep(400);
    await page.click('[data-a2t-merge-confirm]');
    await sleep(1400);
    check('Details still open after merge', await page.$eval(R, (d) => d.open));
    const afterMerge = await turnTexts(page);
    check('one fewer turn', afterMerge.length === beforeMerge.length - 1,
        beforeMerge.length + ' -> ' + afterMerge.length);
    check('merged text present', afterMerge.some((t) => t.includes('Pickup please.') && t.includes('garlic bread')),
        JSON.stringify(afterMerge));

    /* ---------- E. WITH NEXT ---------- */
    console.log('\nE. WITH NEXT');
    await openDetails(page);
    const beforeNext = await turnTexts(page);
    await selectTurnText(page, R, 3);
    await sleep(300);
    const nextChip = await page.$(R + ' [data-a2t-merge-controls]:not([hidden]) [data-a2t-merge-with="next"]');
    check('"With next" offered', !!nextChip);
    if (nextChip) {
        await nextChip.click();
        await sleep(400);
        await page.click('[data-a2t-merge-confirm]');
        await sleep(1400);
        check('Details still open', await page.$eval(R, (d) => d.open));
        check('one fewer turn', (await turnTexts(page)).length === beforeNext.length - 1);
    }

    /* ---------- F. MOVE ---------- */
    console.log('\nF. MOVE');
    await openDetails(page);
    const beforeMove = await page.$$eval(R + ' [data-a2t-turn]', (n) => n.map((t) => t.getAttribute('data-a2t-role')));
    await page.click(R + ' [data-a2t-turn="1"] [data-a2t-move]');
    await sleep(500);
    check('move confirm opened', await page.$eval('[data-a2t-move-dialog]', (d) => d.open));
    const to = await page.$eval('[data-a2t-move-to]', (n) => n.textContent);
    check('names the target speaker', to === 'Customer' || to === 'Agent', to);
    await page.click('[data-a2t-move-confirm]');
    await sleep(1400);
    check('Details still open after move', await page.$eval(R, (d) => d.open));
    const afterMove = await page.$$eval(R + ' [data-a2t-turn]', (n) => n.map((t) => t.getAttribute('data-a2t-role')));
    check('roles changed', JSON.stringify(afterMove) !== JSON.stringify(beforeMove),
        JSON.stringify(beforeMove) + ' -> ' + JSON.stringify(afterMove));

    /* ---------- G. ENDS ---------- */
    console.log('\nG. ENDS');
    await openDetails(page);
    const count = (await page.$$(R + ' [data-a2t-turn]')).length;
    await selectTurnText(page, R, 0);
    await sleep(300);
    check('first turn offers no "With previous"',
        !(await page.$(R + ' [data-a2t-turn="0"] [data-a2t-merge-with="previous"]')));
    await selectTurnText(page, R, count - 1);
    await sleep(300);
    check('last turn offers no "With next"',
        !(await page.$(R + ' [data-a2t-turn="' + (count - 1) + '"] [data-a2t-merge-with="next"]')));

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('CSP violations: ' + JSON.stringify(await page.evaluate(() => window.__csp || [])));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS FAILED:', e.stack); process.exit(2); });
