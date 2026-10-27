/**
 * Case Order call recordings, in a real Chrome: the Recording column, playback, seeking, one-at-a-time,
 * download, the error state, and the Practice Orders picker that lands on them.
 *
 * The server must run with ORDER58_RECORDING_STREAM_SOURCE=fixture — every recording is then a local
 * synthetic tone and no request reaches the provider. run-case-recordings.sh refuses to start otherwise.
 */
const fs = require('fs');
const { BASE, launch, signIn, sleep } = require('./lib');

const fx = JSON.parse(fs.readFileSync(__dirname + '/fixtures-case-recordings.json', 'utf8'));

let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok || detail === undefined ? '' : '  — ' + detail));
    if (!ok) failures++;
}

const recordingPath = (order, id) => '/order-testing/store/' + fx.store + '/order/' + order + '/recording/' + id;

(async () => {
    const { browser, page, errors } = await launch();
    const audioRequests = [];
    // The one 404 this run causes on purpose — the unavailable recording — and nothing else may log.
    const expected404 = recordingPath(fx.gone, fx.recordings[fx.gone][0]);
    const unexpectedConsole = [];
    page.on('console', (m) => {
        if (m.type() !== 'error' || m.text().includes('Content Security Policy')) return;
        const url = (m.location() || {}).url || '';
        if (!(url.endsWith(expected404) && m.text().includes('404'))) {
            unexpectedConsole.push(m.text() + ' @ ' + url);
        }
    });

    page.on('request', (r) => {
        if (r.url().includes('/recording/')) audioRequests.push(r.url());
    });

    try {
        await signIn(page, fx);

        // ---------------------------------------------------------------- the page itself
        await page.goto(BASE + '/order-testing/store/' + fx.store, { waitUntil: 'networkidle0' });
        await page.evaluate(() => document.fonts.ready);
        await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);

        check('no recording is requested when the page opens', audioRequests.length === 0, audioRequests.join(', '));
        check('no native audio players are rendered',
            await page.$$eval('audio', (a) => a.length) === 0);

        const html = await page.content();
        check('the provider URL is nowhere in the page', !html.includes('xrainbow') && !html.includes('15550001111'));

        const cell = await page.evaluate((one, none) => {
            const rowOf = (id) => [...document.querySelectorAll('tr')].find((tr) => tr.textContent.includes('#' + id));
            const r1 = rowOf(one);
            const r0 = rowOf(none);
            const play = r1 && r1.querySelector('[data-a2t-play]');
            const dl = r1 && r1.querySelector('a.ot-rec__download');
            return {
                play: !!play,
                playText: play ? play.textContent.trim() : null,
                download: dl ? dl.getAttribute('href') : null,
                downloadVisibleText: dl ? [...dl.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join('') : null,
                heightWith: r1 ? Math.round(r1.getBoundingClientRect().height) : 0,
                heightWithout: r0 ? Math.round(r0.getBoundingClientRect().height) : 0,
                dash: r0 ? r0.querySelector('.ot-rec__state--none') !== null : false,
            };
        }, fx.one, fx.none);

        check('a recorded order has a Play icon', cell.play && cell.playText === '', JSON.stringify(cell));
        check('and a Download icon pointing at the download address',
            cell.download === recordingPath(fx.one, fx.recordings[fx.one][0]) + '?download=1', cell.download);
        check('icons only — no visible words', cell.downloadVisibleText === '', cell.downloadVisibleText);
        check('the row is no taller than a row without a recording',
            Math.abs(cell.heightWith - cell.heightWithout) <= 2, cell.heightWith + ' vs ' + cell.heightWithout);
        check('an order without a recording still shows the dash', cell.dash);

        // ---------------------------------------------------------------- several recordings
        const before = await page.evaluate((three) => {
            const tr = [...document.querySelectorAll('tr')].find((t) => t.textContent.includes('#' + three));
            return { height: Math.round(tr.getBoundingClientRect().height), summary: tr.querySelector('summary').textContent.trim() };
        }, fx.three);
        check('three recordings are one compact disclosure', before.summary === '3 recordings', before.summary);

        await page.click('tr:has(.ot-rec__more) summary');
        const opened = await page.evaluate((three) => {
            const tr = [...document.querySelectorAll('tr')].find((t) => t.textContent.includes('#' + three));
            return {
                items: tr.querySelectorAll('.ot-rec__item').length,
                plays: tr.querySelectorAll('.ot-rec__item [data-a2t-play]').length,
                downloads: tr.querySelectorAll('.ot-rec__item a.ot-rec__download').length,
            };
        }, fx.three);
        check('opened, it lists each recording with its own Play and Download',
            opened.items === 3 && opened.plays === 3 && opened.downloads === 3, JSON.stringify(opened));

        // ---------------------------------------------------------------- playback
        const first = '[data-a2t-play="' + recordingPath(fx.one, fx.recordings[fx.one][0]) + '"]';
        await page.click(first);
        await sleep(1500);

        const playing = await page.$eval(first, (b) => ({
            playing: b.hasAttribute('data-playing'),
            loading: b.hasAttribute('data-loading'),
            broken: b.classList.contains('is-broken'),
            pressed: b.getAttribute('aria-pressed'),
        }));
        check('pressing Play plays it', playing.playing && !playing.broken && playing.pressed === 'true', JSON.stringify(playing));
        check('and the loading ring is gone once sound starts', !playing.loading, JSON.stringify(playing));
        check('exactly that recording was requested, once',
            audioRequests.length === 1 && audioRequests[0].endsWith(recordingPath(fx.one, fx.recordings[fx.one][0])),
            audioRequests.join(', '));

        const second = '.ot-rec__item [data-a2t-play="' + recordingPath(fx.three, fx.recordings[fx.three][1]) + '"]';
        await page.click(second);
        await sleep(1500);
        const one = await page.$eval(first, (b) => b.hasAttribute('data-playing'));
        const two = await page.$eval(second, (b) => b.hasAttribute('data-playing'));
        check('starting another recording stops the first', !one && two, 'first=' + one + ' second=' + two);

        await page.click(second);
        check('pressing it again pauses it', !(await page.$eval(second, (b) => b.hasAttribute('data-playing'))));

        // ---------------------------------------------------------------- seeking, in a real media element
        const seek = await page.evaluate(async (url) => {
            const audio = new Audio(url);
            audio.muted = true;
            await audio.play();
            await new Promise((r) => setTimeout(r, 300));
            audio.currentTime = 4;
            await new Promise((r) => audio.addEventListener('seeked', r, { once: true }));
            await new Promise((r) => setTimeout(r, 300));
            const result = { duration: audio.duration, currentTime: audio.currentTime, seekable: audio.seekable.length ? audio.seekable.end(0) : 0 };
            audio.pause();
            return result;
        }, BASE + recordingPath(fx.one, fx.recordings[fx.one][0]));
        check('the browser knows the duration', Math.abs(seek.duration - 6) < 0.1, JSON.stringify(seek));
        check('and can seek to the middle', seek.currentTime >= 4 && seek.seekable >= 5.9, JSON.stringify(seek));

        const range = await page.evaluate(async (url) => {
            const r = await fetch(url, { headers: { Range: 'bytes=1000-1999' } });
            const b = await r.arrayBuffer();
            return { status: r.status, length: b.byteLength, range: r.headers.get('content-range') };
        }, BASE + recordingPath(fx.one, fx.recordings[fx.one][0]));
        check('a byte range is a 206 with exactly those bytes',
            range.status === 206 && range.length === 1000 && range.range === 'bytes 1000-1999/' + fx.fixtureBytes,
            JSON.stringify(range));

        // ---------------------------------------------------------------- download
        const download = await page.evaluate(async (url) => {
            const r = await fetch(url);
            const b = await r.arrayBuffer();
            return { status: r.status, disposition: r.headers.get('content-disposition'), type: r.headers.get('content-type'), length: b.byteLength, cache: r.headers.get('cache-control') };
        }, BASE + recordingPath(fx.one, fx.recordings[fx.one][0]) + '?download=1');
        check('Download saves the whole WAV as an attachment',
            download.status === 200 && download.type === 'audio/wav' && download.length === fx.fixtureBytes
                && /^attachment; filename="order-\d+-call-\d+-1\.wav"$/.test(download.disposition),
            JSON.stringify(download));
        check('and nothing is cached', download.cache === 'no-store, private', download.cache);

        // ---------------------------------------------------------------- the error state
        const gone = '[data-a2t-play="' + recordingPath(fx.gone, fx.recordings[fx.gone][0]) + '"]';
        await page.click(gone);
        await sleep(1500);
        const broken = await page.$eval(gone, (b) => ({
            broken: b.classList.contains('is-broken'),
            playing: b.hasAttribute('data-playing'),
            loading: b.hasAttribute('data-loading'),
            title: b.title,
        }));
        check('an unavailable recording says so on its own button',
            broken.broken && !broken.playing && !broken.loading && /could not be played/.test(broken.title),
            JSON.stringify(broken));

        await page.screenshot({ path: __dirname + '/_shots/case-recordings-manage.png', fullPage: true });

        // ---------------------------------------------------------------- Practice Orders
        await page.goto(BASE + '/practice-orders?q=' + encodeURIComponent(fx.storeName), { waitUntil: 'networkidle0' });
        const picker = await page.evaluate(() => ({
            active: (document.querySelector('.filter-chip--active:last-of-type') || {}).textContent,
            chips: [...document.querySelectorAll('.filter-chip--active')].map((c) => c.textContent.trim()),
            card: [...document.querySelectorAll('.store-card__metric')].map((m) => m.textContent.replace(/\s+/g, ' ').trim()),
        }));
        check('Practice Orders lands on Case Order recordings', picker.chips.includes('Case Order recordings'), JSON.stringify(picker.chips));
        check('the card counts orders with recordings (3), with its calls and files',
            picker.card.some((t) => /^3 Orders With Recordings 3 calls · 5 recordings$/.test(t)), JSON.stringify(picker.card));
        check('and Topics Assigned carries no sub-label', picker.card.some((t) => /^0 Topics Assigned$/.test(t)), JSON.stringify(picker.card));

        await page.goto(BASE + '/practice-orders/store/' + fx.store, { waitUntil: 'networkidle0' });
        const practice = await page.evaluate((one, none) => ({
            one: document.body.textContent.includes('#' + one),
            none: document.body.textContent.includes('#' + none),
            plays: document.querySelectorAll('[data-a2t-play]').length,
        }), fx.one, fx.none);
        check('the practice page lists recorded orders and their controls', practice.one && practice.plays >= 3, JSON.stringify(practice));
        check('and leaves out the order with nothing to listen to', !practice.none);
        await page.screenshot({ path: __dirname + '/_shots/case-recordings-practice.png', fullPage: true });

        const pageErrors = errors.filter((e) => e.startsWith('pageerror'));
        check('no script errors', pageErrors.length === 0 && unexpectedConsole.length === 0,
            pageErrors.concat(unexpectedConsole).join(' | '));
        check('no content-security-policy violations', (await page.evaluate(() => window.__csp)).length === 0,
            JSON.stringify(await page.evaluate(() => window.__csp)));
    } catch (e) {
        check('the run completed', false, e.stack);
    } finally {
        await browser.close();
    }

    console.log(failures === 0 ? '\nALL PASSED' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
})();
