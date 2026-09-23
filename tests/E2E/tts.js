/* Text to Audio for a recording that holds one side of a call. */
const { BASE, launch, signIn, sleep } = require('./lib');
const { execFileSync } = require('child_process');
const fx = require('./fixtures.json');

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const store = () => BASE + '/audio-to-text/store/' + fx.store;

// The order holding all three recordings. Selected by its own id rather than by position: the store
// lists newest first, and the spare recording seeded for the last two sections sits above it.
const ORDER = '99001122';
const TTS_BUTTON = '[data-a2t-tts][data-a2t-order="' + ORDER + '"]';
const ROW = 'tr:has(' + TTS_BUTTON + ')';

async function options(page) {
    await page.goto(store(), { waitUntil: 'networkidle0' });
    const url = await page.$eval(TTS_BUTTON, (n) => n.getAttribute('data-a2t-tts'));
    return page.evaluate(async (u) => {
        const r = await fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        return r.json();
    }, url);
}

const byLabel = (data, label) => data.options.find((o) => o.label === label);

/**
 * The Text to Audio cell as a reader sees it: label => state, or 'PLAY' where a play control stands.
 *
 * The cell is a grid of alternating label and state elements, so it is read pairwise rather than as
 * text — `textContent` runs the two together.
 */
function readCell(page) {
    return page.$eval(ROW + ' .a2t-tts-list', (list) => {
        const out = {};
        const kids = [...list.children];
        for (let i = 0; i < kids.length; i += 2) {
            const label = kids[i].textContent.trim();
            const value = kids[i + 1];
            out[label] = value && value.hasAttribute('data-a2t-play') ? 'PLAY' : (value || {}).textContent.trim();
        }
        return out;
    });
}

/** Open the dialog, pick one recording, press Generate — the flow an administrator performs. */
async function generate(page, label) {
    await page.goto(store(), { waitUntil: 'networkidle0' });
    await page.click(TTS_BUTTON);
    await page.waitForSelector('.a2t-tts-dialog .a2t-tts-option', { timeout: 5000 });
    await page.evaluate((label) => {
        const opts = [...document.querySelectorAll('.a2t-tts-dialog .a2t-tts-option')];
        const c = opts.find((o) => o.querySelector('.a2t-tts-option__label').textContent.trim() === label);
        c.querySelector('input[type=radio]').click();
    }, label);
    await sleep(250);
    await page.click('[data-a2t-tts-submit]');
    await sleep(1600);
}

(async () => {
    const { browser, page, errors } = await launch();
    // Every document the browser loads, so "it did not navigate" is asserted rather than assumed.
    const visited = [];
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) visited.push(f.url()); });
    await signIn(page, fx);

    console.log('\nA. THE MODAL OFFERS ALL THREE');
    await page.goto(store(), { waitUntil: 'networkidle0' });
    await page.click(TTS_BUTTON);
    await page.waitForSelector('.a2t-tts-dialog .a2t-tts-option', { timeout: 5000 });
    await sleep(300);
    const shown = await page.$$eval('.a2t-tts-dialog .a2t-tts-option', (n) => n.map((o) => ({
        label: o.querySelector('.a2t-tts-option__label').textContent.trim(),
        disabled: o.hasAttribute('data-disabled'),
        reason: (o.querySelector('.a2t-tts-option__reason') || {}).textContent || '',
    })));
    for (const label of ['Common / Mixed', 'Caller', 'Callee']) {
        const o = shown.find((x) => x.label === label);
        check(label + ' offered', !!o);
        check(label + ' selectable', o && !o.disabled, o && o.reason);
        check(label + ': no speaker-confirmation reason', !(o && /Speaker confirmation/.test(o.reason)));
    }

    console.log('\nB. GENERATE CALLER');
    let data = await options(page);
    const caller = byLabel(data, 'Caller');
    check('backend says selectable', caller.selectable === true, caller.reason);
    check('output type is MIXED for this recording', caller.outputType === 'MIXED', caller.outputType);
    check('names the caller job', caller.jobPublicId === fx.callerJob, caller.jobPublicId);
    check('carries an expected hash', typeof caller.expectedHash === 'string' && caller.expectedHash.length === 64);

    await generate(page, 'Caller');
    check('URL is still the store page', page.url() === store(), page.url());
    check('never visited the ai-audio page', !visited.some((u) => u.includes('/ai-audio')),
        visited.filter((u) => u.includes('ai-audio')).join(', '));
    check('dialog closed', !(await page.$eval('.a2t-tts-dialog', (d) => d.open)));
    check('success notice shown', await page.$eval('[data-a2t-notice]', (n) => !n.hidden));
    check('notice names the recording', (await page.$eval('[data-a2t-notice]', (n) => n.textContent))
        .startsWith('Caller text-to-audio has been queued'),
        await page.$eval('[data-a2t-notice]', (n) => n.textContent.trim()));

    const cells = await readCell(page);
    check('caller cell now says Queued', cells.Caller === 'Queued', JSON.stringify(cells));
    check('mixed cell untouched', cells['Common / Mixed'] === 'Not generated');
    check('callee cell untouched', cells.Callee === 'Not generated');

    data = await options(page);
    const queued = byLabel(data, 'Caller');
    check('caller is now in flight', queued.state === 'in-flight', queued.state + ' / ' + queued.reason);
    check('mixed unaffected', byLabel(data, 'Common / Mixed').state === 'ready');
    check('callee unaffected', byLabel(data, 'Callee').state === 'ready');

    console.log('\nC. THE WORKER FINISHES (stand-in: no provider is called)');
    execFileSync('php', [__dirname + '/complete-tts.php', fx.callerJob], { encoding: 'utf8' });
    await page.goto(store(), { waitUntil: 'networkidle0' });
    const ready = await readCell(page);
    check('caller row now offers play', ready.Caller === 'PLAY', JSON.stringify(ready));
    check('mixed still not generated', ready['Common / Mixed'] === 'Not generated');
    check('callee still not generated', ready.Callee === 'Not generated');
    data = await options(page);
    check('caller reports current', byLabel(data, 'Caller').state === 'current', byLabel(data, 'Caller').state);
    check('caller not selectable while current', byLabel(data, 'Caller').selectable === false);

    console.log('\nD. EDIT MAKES IT STALE');
    await page.goto(BASE + '/audio-to-text/job/' + fx.callerJob + '/review', { waitUntil: 'networkidle0' });
    await page.click('[data-a2t-turn="0"] [data-a2t-edit]');
    await sleep(250);
    await page.$eval('[data-a2t-turn="0"] [data-a2t-editor-text]', (t) => { t.value = 'TTS STALENESS E2E.'; });
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-a2t-turn="0"] [data-a2t-edit-save]'),
    ]);
    data = await options(page);
    const stale = byLabel(data, 'Caller');
    check('caller is selectable again', stale.selectable === true, stale.state + ' / ' + stale.reason);
    check('its hash changed', stale.expectedHash !== caller.expectedHash);

    console.log('\nE. THE OTHER TWO RECORDINGS');
    for (const label of ['Common / Mixed', 'Callee']) {
        await generate(page, label);
        check(label + ': stayed on the store page', page.url() === store(), page.url());
        const c = await readCell(page);
        check(label + ': its own cell moved', c[label] === 'Queued', JSON.stringify(c));
        check(label + ': caller left alone', c.Caller === 'PLAY' || c.Caller === 'Queued', c.Caller);
    }

    console.log('\nF. THE CELL REACHES READY WITHOUT A RELOAD');
    // Callee was queued in E and the watcher is running on this very page, so the render is finished
    // underneath it and nothing is reloaded — which is the whole assertion.
    const before = page.url();
    execFileSync('php', [__dirname + '/complete-tts.php', fx.calleeJob], { encoding: 'utf8' });
    // The watcher's first tick is at 2.5s and then every 4s; two ticks is ample.
    await sleep(9000);
    const polled = await readCell(page);
    check('callee became playable in place', polled.Callee === 'PLAY', JSON.stringify(polled));
    check('and never navigated', page.url() === before, page.url());

    console.log('\nG. A REFUSAL KEEPS THE DIALOG OPEN');
    await page.goto(store(), { waitUntil: 'networkidle0' });
    await page.click(TTS_BUTTON);
    await page.waitForSelector('.a2t-tts-dialog .a2t-tts-option', { timeout: 5000 });
    await page.evaluate(() => {
        const o = [...document.querySelectorAll('.a2t-tts-dialog .a2t-tts-option')]
            .find((x) => x.querySelector('.a2t-tts-option__label').textContent.trim() === 'Caller');
        o.querySelector('input[type=radio]').click();
        // What a hand-made request looks like: an output this recording cannot produce.
        document.querySelector('[data-a2t-tts-output]').value = 'BANJO';
    });
    await sleep(200);
    await page.click('[data-a2t-tts-submit]');
    await sleep(1400);
    check('dialog stayed open', await page.$eval('.a2t-tts-dialog', (d) => d.open));
    check('error shown inside it', await page.$eval('[data-a2t-tts-status]', (n) => !n.hidden));
    check('Generate is usable again', !(await page.$eval('[data-a2t-tts-submit]', (b) => b.disabled)));
    check('still on the store page', page.url() === store(), page.url());

    console.log('\nH. THE OLD AI AUDIO PAGE IS UNCHANGED');
    const aiAudio = BASE + '/audio-to-text/conversion/' + fx.spareConversation + '/ai-audio';
    await page.goto(aiAudio, { waitUntil: 'networkidle0' });
    const form = await page.$('form[action*="ai-audio/generate"]');
    check('its form is there', !!form);
    if (form) {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form[action*="ai-audio/generate"] button[type=submit]'),
        ]);
        check('it still redirects back to itself', page.url() === aiAudio, page.url());
        check('and still flashes', (await page.$eval('body', (b) => b.innerText)).includes('has been queued'));
    }

    console.log('\nI. MIXED STILL NEEDS ITS SPEAKERS (this makes it ineligible, so it runs last)');
    // Take the mixed recording's confirmation away and confirm the old rule still bites.
    execFileSync('php', ['-r', `
        require '${process.env.KF_ROOT || '/var/www/html/knowledge-forge'}/vendor/autoload.php';
        Dotenv\\Dotenv::createImmutable('${process.env.KF_ROOT || '/var/www/html/knowledge-forge'}')->safeLoad();
        App\\Environment::prepare();
        $c = (new App\\Shared\\Infrastructure\\Db\\DbConnectionFactory(new App\\Shared\\Infrastructure\\Db\\DbParams(
            host: App\\Environment::string('DB_HOST'), port: App\\Environment::int('DB_PORT'),
            name: App\\Environment::string('DB_NAME'), user: App\\Environment::string('DB_USER'),
            password: App\\Environment::string('DB_PASSWORD'), charset: App\\Environment::string('DB_CHARSET'),
            socket: App\\Environment::string('DB_SOCKET'),
        ), new Yiisoft\\Db\\Cache\\SchemaCache(new Yiisoft\\Cache\\ArrayCache())))->create();
        $c->createCommand()->update('{{%audio_transcription_jobs}}',
            ['speaker_separation_status' => 'NEEDS_REVIEW', 'roles_confirmed_at' => null],
            ['public_id' => '${fx.job}'])->execute();
    `], { encoding: 'utf8' });

    data = await options(page);
    const mixed = byLabel(data, 'Common / Mixed');
    check('mixed is blocked', mixed.selectable === false, mixed.state + ' / ' + mixed.reason);
    check('and says why', /Speaker confirmation required/.test(mixed.reason || ''), mixed.reason);
    check('caller is still fine', byLabel(data, 'Caller').selectable === true);

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS FAILED:', e.stack); process.exit(2); });
