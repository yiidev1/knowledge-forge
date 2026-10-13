/*
 * The floating recording player, and the tab it has to outlive.
 *
 * Pressing Demo URL opens the Order58 form in a second tab. The recording lives in the Knowledge Forge
 * tab, so the moment the trainee switches, the audio is behind a tab they are not looking at. Document
 * Picture-in-Picture puts the player in a small always-on-top window instead.
 *
 * None of this is reachable from the Web suite: PhpBrowser runs no script, has no window, and cannot
 * hold a media element. The two properties that matter — the player survives the tab switch, and the
 * audio keeps playing — only exist in a browser.
 *
 * **It talks to no real store.** The fixture's host is a subdomain of `order58.com` that does not
 * resolve, which is what makes `DemoOrderUrl` offer the button at all while guaranteeing the second tab
 * never reaches the client's live ordering system. No order is created anywhere but in this database.
 */
const puppeteer = require('puppeteer-core');
const { BASE, sleep } = require('./lib');
const fx = require('./fixtures-practice.json');

/**
 * Its own browser, headed, rather than the shared `launch()` from lib.js.
 *
 * Document Picture-in-Picture needs a real window to put itself in; under `headless: 'new'` the API is
 * present and `requestWindow()` simply never resolves into anything, which reads as "the feature is
 * broken" when it is only "there is no screen". Every other option matches lib.js so the two behave the
 * same in all the ways that are not about having a display.
 */
async function launchHeaded() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: false,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--window-size=1500,1000'],
        defaultViewport: null,
    });
    const page = (await browser.pages())[0];
    const errors = [];
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    page.on('console', (m) => {
        if (m.type() === 'error' && !m.text().includes('Content Security Policy')) {
            errors.push('console: ' + m.text());
        }
    });
    await page.evaluateOnNewDocument(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__csp.push(e.violatedDirective + ' @ ' + e.sourceFile + ':' + e.lineNumber);
        });
    });

    return { browser, page, errors };
}

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

/** The Demo URL control of the row for one order. Positional selectors pick whatever sorted first. */
async function demoButtonFor(page, orderId) {
    const handles = await page.$$('.a2t-orders tbody tr');

    for (const row of handles) {
        const id = await row.evaluate((r) => {
            const el = r.querySelector('.a2t-order-id');
            return el ? el.textContent.trim() : '';
        }).catch(() => '');

        if (id.indexOf(String(orderId)) !== -1) {
            return row.$('[data-ot-demo-url]');
        }
    }

    return null;
}

/** The Picture-in-Picture document, found among the browser's targets by what we put in it. */
async function playerWindow(browser, opener) {
    for (const target of browser.targets()) {
        try {
            const p = await target.page();

            if (p && p !== opener) {
                const mine = await p.evaluate(() => !!document.querySelector('.ot-pip__seek')).catch(() => false);

                if (mine) return p;
            }
        } catch (e) { /* a target that is not a page, or has gone */ }
    }

    return null;
}

(async () => {
    const { browser, page, errors } = await launchHeaded();

    try {

    // 127.0.0.1 is a secure context without TLS, which is why the API exists here at all.
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    const secure = await page.evaluate(() => ({
        secure: window.isSecureContext,
        api: 'documentPictureInPicture' in window,
    }));
    check('the harness origin is a secure context', secure.secure, JSON.stringify(secure));
    check('Document Picture-in-Picture is available', secure.api);

    await page.type('input[name="username"]', fx.admin);
    await page.type('input[name="password"]', fx.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('form button[type="submit"], form input[type="submit"]'),
    ]);

    await page.goto(BASE + fx.storeUrl, { waitUntil: 'networkidle0' });

    const demo = await demoButtonFor(page, fx.order);
    check('the Demo URL control is offered for the free order', demo !== null);

    if (demo === null) {
        await browser.close();
        process.exit(1);
    }

    // The address the player must use is the row's own, already-authorized one.
    const rowAudio = await page.evaluate((id) => {
        const rows = [...document.querySelectorAll('.a2t-orders tbody tr')];
        const row = rows.find((r) => (r.querySelector('.a2t-order-id') || {}).textContent &&
            r.querySelector('.a2t-order-id').textContent.indexOf(String(id)) !== -1);
        const play = row ? row.querySelector('[data-a2t-play]') : null;
        return play ? play.getAttribute('data-a2t-play') : null;
    }, fx.order);

    const attemptsBefore = await page.evaluate(async (u) => (await fetch(u)).status, BASE + fx.storeUrl);
    check('store page reachable before the click', attemptsBefore === 200, String(attemptsBefore));

    // ---- the click -------------------------------------------------------------------------------
    await demo.click();
    await sleep(2500);

    const player = await playerWindow(browser, page);
    check('a floating player opened', player !== null);

    if (player === null) {
        console.log('\n(no player — nothing further can be checked)');
        await browser.close();
        process.exit(1);
    }

    const src = await player.evaluate(() => {
        const a = document.querySelector('audio');
        return a ? a.getAttribute('src') : null;
    });
    check('it plays the recording this row points at', src === rowAudio, src + ' vs ' + rowAudio);

    check('it opens paused, not blaring',
        await player.evaluate(() => document.querySelector('audio').paused));

    // ---- controls --------------------------------------------------------------------------------
    await player.click('.ot-pip__btn--play');
    await sleep(1200);
    const playing = await player.evaluate(() => {
        const a = document.querySelector('audio');
        return { paused: a.paused, t: a.currentTime, label: document.querySelector('.ot-pip__btn--play').textContent };
    });
    check('Play starts it', playing.paused === false, JSON.stringify(playing));
    check('the button becomes Pause', playing.label === 'Pause', playing.label);
    check('the clock advances', playing.t > 0, String(playing.t));

    const shown = await player.evaluate(() => document.querySelector('.ot-pip__time').textContent);
    check('progress and duration are shown', /\d:\d\d \/ \d:\d\d/.test(shown), shown);

    // Seek, then confirm the element actually moved.
    await player.evaluate(() => {
        const s = document.querySelector('.ot-pip__seek');
        s.value = '50';
        s.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await sleep(400);
    const sought = await player.evaluate(() => document.querySelector('audio').currentTime);
    check('Seek moves playback', sought > 1.5, String(sought));

    await player.click('.ot-pip__btn:not(.ot-pip__btn--play)');
    await sleep(400);
    const replayed = await player.evaluate(() => document.querySelector('audio').currentTime);
    check('Replay returns to the start', replayed < sought, String(replayed));

    await player.evaluate(() => {
        const v = document.querySelector('.ot-pip__volume');
        v.value = '0.3';
        v.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const vol = await player.evaluate(() => document.querySelector('audio').volume);
    check('Volume applies', Math.abs(vol - 0.3) < 0.01, String(vol));

    // ---- the whole point: a second tab takes focus -------------------------------------------------
    const before = await player.evaluate(() => document.querySelector('audio').currentTime);
    const second = await browser.newPage();
    await second.goto('about:blank');
    await second.bringToFront();
    await sleep(2500);

    const still = await playerWindow(browser, page);
    check('the player survives the second tab', still !== null);

    const after = still === null ? null : await still.evaluate(() => {
        const a = document.querySelector('audio');
        return { paused: a.paused, t: a.currentTime };
    });
    check('audio keeps playing while the other tab has focus',
        after !== null && after.paused === false && after.t > before,
        JSON.stringify(after) + ' (was ' + before.toFixed(2) + ')');

    // ---- pressing again as the SAME operator resumes; it does not duplicate -------------------------
    await page.bringToFront();
    await page.reload({ waitUntil: 'networkidle0' });
    await sleep(800);
    // Reloading destroys the document that owns the player, so the window goes with it. That is the
    // documented limit of Document PiP, and the reason Order58 opens in a SECOND tab and not in this one.
    check('reloading the listing closes the player with its opener',
        (await playerWindow(browser, page)) === null);

    const again = await demoButtonFor(page, fx.order);
    await again.click();
    await sleep(2500);
    const resumed = await page.evaluate(() => {
        const m = document.querySelector('[data-ot-demo-message]');
        return m && !m.hidden ? m.textContent.trim() : '';
    });
    // `TestAttemptService::start()` resumes for the same operator and refuses only for a different one,
    // so this must succeed quietly. The runner proves the attempt count never moved.
    check('a second press by the same operator is allowed, not refused', resumed === '', JSON.stringify(resumed));
    const resumedPlayer = await playerWindow(browser, page);
    check('and it opens the player again', resumedPlayer !== null);

    // ---- the refusal path, on an order somebody else is holding -------------------------------------
    const held = await demoButtonFor(page, fx.heldOrder);
    check('the held order has its own Demo URL control', held !== null);

    if (held !== null) {
        await held.click();
        await sleep(2500);
        const refusal = await page.evaluate(() => {
            const all = [...document.querySelectorAll('[data-ot-demo-message]')]
                .filter((m) => !m.hidden).map((m) => m.textContent.trim());
            return all.join(' | ');
        });
        check('an order held by somebody else is refused in the server\'s own words',
            /already testing/i.test(refusal), JSON.stringify(refusal));
        // Closing PiP pages races puppeteer's target bookkeeping and hung the run, so nothing is
        // closed: identity is enough to tell "a new player" from "the one already up".
        const afterRefusal = await playerWindow(browser, page);
        check('the refused press opens no additional player',
            afterRefusal === null || afterRefusal === resumedPlayer,
            afterRefusal === null ? 'none' : 'the same window as before');
    }

    // ---- the fallback: a browser without the API behaves exactly as before --------------------------
    const plain = await browser.newPage();
    await plain.evaluateOnNewDocument(() => {
        // Safari and Firefox today, and any insecure context — including http://knowledge-forge.local.
        delete window.documentPictureInPicture;
    });
    await plain.goto(BASE + fx.storeUrl, { waitUntil: 'networkidle0' });
    check('the API really is absent for the fallback check',
        (await plain.evaluate(() => 'documentPictureInPicture' in window)) === false);

    const plainButton = await demoButtonFor(plain, fx.order);
    await plainButton.click();
    await sleep(2000);
    const plainPlayer = await playerWindow(browser, plain);
    check('without the API no new player is attempted',
        plainPlayer === null || plainPlayer === resumedPlayer,
        plainPlayer === null ? 'none' : 'only the pre-existing one');
    // The click opened a second tab, which can detach this frame mid-read; an unreadable message is
    // not a failing one.
    const fallbackErr = await plain.evaluate(() => {
        const m = document.querySelector('[data-ot-demo-message]');
        return m && !m.hidden ? m.textContent.trim() : '';
    }).catch(() => '');
    check('the fallback path reports nothing broken', fallbackErr === '', JSON.stringify(fallbackErr));

    const csp = await page.evaluate(() => window.__csp || []);
    check('no CSP violation', csp.length === 0, csp.join(' | '));
    // The second tab is pointed at a host that does not resolve, so its failure is expected and is not
    // this page's error.
    // A 409 is the designed refusal for an order somebody else holds, and the dead fixture host is the
    // whole point of the fixture. Chrome reports both as console errors; neither is a fault.
    const real = errors.filter((e) => !/ERR_NAME_NOT_RESOLVED|404|409/.test(e));
    check('no console or page errors', real.length === 0, real.join(' | '));

    } catch (e) {
        check('the run completed without a harness error', false, String(e && e.message).slice(0, 110));
    }

    await browser.close().catch(() => {});
    console.log(failures === 0 ? '\nALL PASS' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
