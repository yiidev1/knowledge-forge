/*
 * The agent practice surface, in a real browser, signed in as a real agent.
 *
 * ## Why this needs its own fixture
 *
 * Agent sign-in calls a live Order58 host this environment does not have, so no suite here can obtain
 * a session the ordinary way. `seed-agent-session.php` writes the session the ordinary way would have
 * written — a mirrored agent row and a PHP session file holding exactly what the identity store puts
 * under `kf.agent` — and this sets that cookie. No application code and no authentication wiring is
 * touched; it is the local equivalent of logging in.
 *
 * ## What only a browser can answer
 *
 * That the filter does not apply until Apply is pressed, that Clear removes it, that the read-only
 * page really has no mutating control in its DOM, and that the conversation dialog opens and plays.
 *
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/agent-practice.js <sessionId> <storeId>
 */
const puppeteer = require('puppeteer-core');
const { BASE, sleep } = require('./lib');

const SESSION_ID = process.argv[2];
const STORE = process.argv[3];

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function run() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1500, height: 1000 },
    });
    const page = await browser.newPage();
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

    const url = new URL(BASE);
    await browser.setCookie({ name: 'KFSESSID', value: SESSION_ID, domain: url.hostname, path: '/' });

    try {
        const storeUrl = BASE + '/agent/practice-orders/store/' + STORE;

        // ---------------------------------------------------------------- signed in, and it is them
        await page.goto(BASE + '/agent/practice-orders', { waitUntil: 'networkidle0' });

        check('the agent picker opens', page.url().endsWith('/agent/practice-orders'), page.url());
        check('signed in as the seeded agent', await page.evaluate(
            () => document.body.textContent.indexOf('Browser Agent') !== -1,
        ));
        check('the sidebar offers Practice Orders', await page.evaluate(
            () => document.querySelector('a[href="/agent/practice-orders"]') !== null,
        ));

        // ---------------------------------------------------------------- the listing
        await page.goto(storeUrl, { waitUntil: 'networkidle0' });

        const rows = () => page.evaluate(
            () => Array.from(document.querySelectorAll('tbody tr'))
                .map((tr) => (tr.textContent.match(/#\d+/) || [''])[0])
                .filter((v) => v !== ''),
        );

        const all = await rows();
        check('the store page lists its practisable orders', all.length > 0, all.join(' '));
        check('and not the recording whose order was never mirrored',
            all.indexOf('#111') === -1, all.join(' '));

        // ---------------------------------------------------------------- read-only, in the real DOM
        const controls = await page.evaluate(() => ({
            assignSelects: document.querySelectorAll('[data-practice-assign-select]').length,
            checkboxes: document.querySelectorAll('[data-practice-select]').length,
            bulk: document.querySelectorAll('#practice-bulk-form').length,
            confirm: document.querySelectorAll('#practice-confirm-dialog').length,
            upload: document.querySelectorAll('[data-a2t-open="a2t-upload-dialog"], #a2t-upload-dialog').length,
            manage: document.querySelectorAll('#a2t-manage-dialog, [data-a2t-manage]').length,
            tts: document.querySelectorAll('#a2t-tts-dialog, #a2t-tts-confirm-dialog').length,
            transcribe: document.querySelectorAll('#a2t-transcribe-dialog, [data-a2t-transcribe]').length,
            fullEditor: document.querySelectorAll('[data-a2t-full-editor], [data-a2t-details-full]').length,
            reviewToken: document.querySelectorAll('[data-a2t-review-token]').length,
            forms: Array.from(document.querySelectorAll('form'))
                .map((f) => (f.getAttribute('method') || 'get').toLowerCase()),
        }));

        check('no topic assignment control exists',
            controls.assignSelects + controls.checkboxes + controls.bulk + controls.confirm === 0,
            JSON.stringify(controls));
        check('no audio or transcript management control exists',
            controls.upload + controls.manage + controls.tts + controls.transcribe
                + controls.fullEditor + controls.reviewToken === 0,
            JSON.stringify(controls));

        // The only POST the page itself may carry is Demo URL. Scoped past the layout: signing out is
        // a POST in the sidebar and belongs to every agent page, not to this one.
        const posts = await page.evaluate(() => Array.from(document.querySelectorAll('.content form'))
            .filter((f) => (f.getAttribute('method') || 'get').toLowerCase() === 'post')
            .map((f) => f.getAttribute('action') || ''));

        // A fixture store is deliberately hostless, so no demo link is offered and no request from
        // this run can reach a real ordering system. Only a real store is asked for the form.
        const fixtureStore = /^9876559\d\d$/.test(String(STORE));

        if (fixtureStore) {
            check('a hostless fixture store offers no Demo URL', posts.length === 0, posts.join(' '));
        } else {
            check('the page carries at least one Demo URL form', posts.length > 0, posts.join(' '));
        }
        check('and every POST on it is a Demo URL at the agent address',
            posts.every((a) => a.indexOf('/agent/practice-orders/') === 0 && a.indexOf('/demo-url') !== -1),
            posts.join(' | '));

        // ---------------------------------------------------------------- the filter
        const before = (await rows()).length;

        await page.click('[data-practice-filter-picker] summary');
        await sleep(200);

        const boxes = await page.evaluate(
            () => Array.from(document.querySelectorAll('[data-practice-filter-choice]'))
                .map((b) => b.value),
        );

        check('the picker offers Not assigned and the active topics',
            boxes.indexOf('none') !== -1 && boxes.length > 1, boxes.join(', '));

        await page.evaluate(() => {
            document.querySelector('[data-practice-filter-choice][value="none"]').click();
        });
        await sleep(500);

        check('ticking a choice applies nothing',
            (await rows()).length === before && page.url() === storeUrl, page.url());
        check('the summary counts what is ticked', await page.evaluate(
            () => document.querySelector('[data-practice-filter-summary]').textContent.trim(),
        ) === '1 selected');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter button[type="submit"]'),
        ]);

        const filtered = await rows();
        check('Apply narrows the list', filtered.length < before && filtered.length > 0,
            filtered.join(' '));
        check('and puts the choice in the address', page.url().indexOf('topics') !== -1, page.url());
        check('which comes back ticked', await page.evaluate(
            () => document.querySelector('[data-practice-filter-choice][value="none"]').checked,
        ));

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.practice-filter a.btn'),
        ]);

        check('Clear removes every restriction',
            page.url() === storeUrl && (await rows()).length === before, page.url());

        // ---------------------------------------------------------------- the same two UI rules here
        //
        // The agent reads this page through the same template and the same script as the other two
        // realms, so the two changes made for them have to be true here as well — and "it is the same
        // file" is an argument, not a measurement.
        check('the topic is plain text on the agent surface too', await page.evaluate(() => {
            const value = document.querySelector('.practice-topic__value');

            return value !== null
                && document.querySelector('[data-practice-topic-details]') === null
                && getComputedStyle(value).cursor !== 'pointer';
        }));

        const agentPicker = '[data-practice-filter-picker]';

        await page.click(agentPicker + ' summary');
        await sleep(200);
        check('the agent filter opens', await page.evaluate(
            (sel) => document.querySelector(sel).open, agentPicker,
        ));

        await page.click('h1');
        await sleep(200);
        check('and closes when the agent clicks outside it', await page.evaluate(
            (sel) => document.querySelector(sel).open === false, agentPicker,
        ));

        // ---------------------------------------------------------------- the conversation dialog
        // "View conversation" — the control an agent actually uses, and the only one in the row that is
        // unconditionally visible. The per-channel Details buttons sit inside a collapsed group, and
        // `$channelIndicator` renders a hidden span carrying the same attribute, so neither is a safe
        // thing for a test to aim at.
        // Everything the dialog asks for, recorded. A read-only payload is not proof on its own: the
        // script fetches some addresses the moment a recording opens, and one of those leaving the
        // agent's own space is how a whole different page ended up inside this dialog once already.
        const requested = [];
        page.on('request', (r) => requested.push(r.url()));

        await page.click('[data-a2t-order-open]');
        await sleep(1800);

        const offSurface = requested
            .filter((u) => u.indexOf('data:') !== 0 && u.indexOf(BASE) === 0)
            .map((u) => new URL(u).pathname)
            .filter((pathname) => pathname.indexOf('/agent/') !== 0 && pathname.indexOf('/assets/') !== 0);

        check('the dialog asks for nothing outside the agent surface',
            offSurface.length === 0, offSurface.join(' '));

        const dialog = await page.evaluate(() => {
            const el = document.querySelector('#a2t-review-dialog');

            if (el === null) {
                return { present: false };
            }

            return {
                present: true,
                modal: el.matches(':modal'),
                players: el.querySelectorAll('audio, [data-a2t-play]').length,
                // What the dialog is FOR: the two sides of the call, as text. A dialog that opens
                // with an empty body is the complaint this check exists to catch.
                turns: el.querySelectorAll(
                    '[data-a2t-segment], .a2t-segment, .a2t-turn, .a2t-message',
                ).length,
                // The turns' own words, not the dialog's — the dialog also carries its progress
                // panel and its audio notices, and a slice of all of it says nothing about either.
                text: (() => {
                    const thread = el.querySelector('.a2t-thread');

                    return (thread === null ? '' : thread.textContent || '')
                        .replace(/\s+/g, ' ').trim().slice(0, 400);
                })(),
                mutators: el.querySelectorAll(
                    '[data-a2t-review-actions], [data-a2t-full-editor], [contenteditable="true"]',
                ).length,
                forms: el.querySelectorAll('form').length,
            };
        });

        check('the conversation dialog opens as a real modal', dialog.present && dialog.modal,
            JSON.stringify(dialog));
        check('with no editing controls in it', dialog.mutators === 0, JSON.stringify(dialog));
        check('the conversation itself is in it', dialog.turns > 0, String(dialog.turns));
        // Only the fixture's own words can be named. A real store's call says whatever it says, and the
        // turn count above is the assertion that applies to both.
        if (fixtureStore) {
            check('and both sides of the fixture call are readable',
                /pepperoni/i.test(dialog.text) && /what can i get you/i.test(dialog.text), dialog.text);
        } else {
            check('and the turns carry words', dialog.text.length > 10, dialog.text);
        }

        // ---------------------------------------------------------------- does the audio actually play
        // A player is not playback. The dialog can hold a perfectly good <audio> whose source answers
        // 404, and from the outside the two look identical: a control that does nothing when pressed.
        // So the sources are fetched, from inside the page and with the session cookie, and judged on
        // what comes back.
        const sources = await page.evaluate(() => {
            const el = document.querySelector('#a2t-review-dialog');
            const urls = [];

            el.querySelectorAll('audio').forEach((a) => {
                if (a.currentSrc !== '') { urls.push(a.currentSrc); }
                else if (a.src !== '') { urls.push(a.src); }
                a.querySelectorAll('source').forEach((srcEl) => {
                    if (srcEl.src !== '') { urls.push(srcEl.src); }
                });
            });

            el.querySelectorAll('[data-a2t-play]').forEach((b) => {
                const u = b.getAttribute('data-a2t-play') || b.getAttribute('data-src');
                if (u) { urls.push(new URL(u, location.href).href); }
            });

            return Array.from(new Set(urls));
        });

        check('the dialog offers at least one audio source', sources.length > 0, sources.join(' '));
        check('every audio source is an agent address',
            sources.every((u) => new URL(u).pathname.indexOf('/agent/practice-orders/') === 0),
            sources.join(' '));

        const fetched = await page.evaluate(async (urls) => {
            const out = [];

            for (const u of urls) {
                try {
                    const r = await fetch(u, { credentials: 'same-origin' });
                    const b = await r.blob();
                    out.push({ url: u, status: r.status, type: r.headers.get('content-type'), bytes: b.size });
                } catch (e) {
                    out.push({ url: u, status: 0, type: null, bytes: 0, error: String(e) });
                }
            }

            return out;
        }, sources);

        check('every audio source answers 200',
            fetched.every((f) => f.status === 200), JSON.stringify(fetched));
        check('with audio bytes behind it',
            fetched.every((f) => f.bytes > 0 && /audio|octet-stream/.test(f.type || '')),
            JSON.stringify(fetched));

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
