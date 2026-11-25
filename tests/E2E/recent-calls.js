/*
 * Order58 Recent Calls in a real browser.
 *
 * What only a browser can answer: that Load Calls fills the table and nothing else is fetched; that no
 * recording is requested until Play or Download is pressed, and then only that channel; that one
 * recording plays at a time; that a slow answer for the previous store never paints over the current
 * one; and that the table fits without a sideways page scroll. (That the session is released before
 * the provider is asked — so a slow answer cannot hold up the same person's other pages — is proved
 * in tests/Unit/Order58/RecentCallsTest.php.)
 *
 *   php tests/E2E/seed-recent-calls.php > tests/E2E/fixtures.json
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/recent-calls.js
 *   php tests/E2E/seed-recent-calls.php --cleanup
 *
 * The call list and recordings come from the repository's sample files (`?source=fixture`, development
 * and test only), so no request reaches the recording provider.
 */
const fs = require('fs');
const path = require('path');
const { BASE, launch, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
const OUT = process.env.KF_SHOTS || path.join(__dirname, '_shots');

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function loadCalls(page) {
    await page.click('[data-rc-load]');
    await page.waitForFunction(
        () => !document.querySelector('[data-rc-load]').disabled
            && document.querySelector('[data-rc-body] .rc-placeholder') === null,
        { timeout: 15000 },
    );
}

async function run() {
    const { browser, page, errors } = await launch();
    fs.mkdirSync(OUT, { recursive: true });

    const requests = [];
    page.on('request', (r) => requests.push(r.url()));

    await signIn(page, fx);

    await fixtures(page);
    await layout(page);

    const csp = await page.evaluate(() => window.__csp || []);
    check('no content-security-policy violations', csp.length === 0, csp.join(' | '));
    check('no script errors', errors.length === 0, errors.join(' | '));

    await browser.close();
    console.log(failures === 0 ? '\nALL PASSED' : '\n' + failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);

    async function fixtures(page) {
        await page.goto(BASE + '/admin/order58/recent-calls?source=fixture', { waitUntil: 'networkidle0' });

        const before = requests.filter((u) => u.includes('/recent-calls/list') || u.includes('/recent-calls/audio'));
        check('opening the page fetches no calls and no audio', before.length === 0, before.join(', '));

        await loadCalls(page);
        const table = await page.evaluate(() => {
            const rows = Array.from(document.querySelectorAll('[data-rc-body] tr'));
            const doc = document.documentElement;
            return {
                rows: rows.length,
                cells: rows.map((r) => r.children.length),
                firstPairs: rows[0] ? rows[0].querySelectorAll('.rc-pair').length : 0,
                lastPairs: rows[2] ? rows[2].querySelectorAll('.rc-pair').length : -1,
                session: rows[0] ? rows[0].children[1].textContent : '',
                receipt: rows[0] ? rows[0].children[6].textContent.trim() : '',
                status: document.querySelector('[data-rc-status]').textContent,
                pageScroll: doc.scrollWidth - doc.clientWidth,
                buttonEnabled: !document.querySelector('[data-rc-load]').disabled,
            };
        });
        check('Load Calls lists the three sample calls', table.rows === 3, table.rows);
        check('every row has the seven columns', table.cells.every((n) => n === 7), table.cells.join(','));
        check('a call with a date has Play and Download for all three channels', table.firstPairs === 3, table.firstPairs);
        check('a call without a readable date lists without audio', table.lastPairs === 0, table.lastPairs);
        check('the session id is shown', table.session === '22342359', table.session);
        check('an order this application has not mirrored shows no receipt link', table.receipt === '—', table.receipt);
        check('the status line names the store', table.status.includes('Best Chinese Cuisine II'), table.status);
        check('the button is usable again', table.buttonEnabled);
        check('the page does not scroll sideways at 1500px', table.pageScroll <= 0, table.pageScroll + 'px');

        const audioBefore = requests.filter((u) => u.includes('/recent-calls/audio')).length;
        check('loading calls requested no audio at all', audioBefore === 0, audioBefore);

        // Play the caller channel of the first call. The samples are a fraction of a second of tone, so
        // the playing state is read in the same tick as the press, before the recording can end.
        const first = await page.evaluate(() => {
            const caller = document.querySelector('[data-rc-body] tr').children[4].querySelector('.rc-play');
            caller.click();
            return caller.hasAttribute('data-playing');
        });
        await wait(800);
        const audioAfter = requests.filter((u) => u.includes('/recent-calls/audio'));
        check('Play requested that one channel only',
            audioAfter.length >= 1 && audioAfter.every((u) => u.includes('channel=caller')), audioAfter.join(', '));
        check('the pressed control shows it is playing', first);

        const states = await page.evaluate(() => {
            const row = document.querySelector('[data-rc-body] tr');
            const caller = row.children[4].querySelector('.rc-play');
            const callee = row.children[5].querySelector('.rc-play');
            caller.click();
            callee.click();
            const switched = !caller.hasAttribute('data-playing') && callee.hasAttribute('data-playing');
            const count = document.querySelectorAll('[data-rc-body] .rc-play[data-playing]').length;
            callee.click();
            const paused = document.querySelectorAll('[data-rc-body] .rc-play[data-playing]').length === 0;
            return { switched, count, paused };
        });
        check('pressing another channel stops the first', states.switched);
        check('one recording plays at a time', states.count === 1, states.count);
        check('pressing again pauses it', states.paused);
        const broken = await page.$$eval('[data-rc-body] .rc-play.is-broken', (b) => b.length);
        check('the sample recordings played without error', broken === 0, broken);

        const download = await page.$eval('[data-rc-body] tr:first-child td:nth-child(4) .rc-download', (a) => ({
            href: a.getAttribute('href'), name: a.getAttribute('download'),
        }));
        check('Download names the file by session and channel', download.name === '22342359.wav', download.name);
        check('Download asks for an attachment', download.href.includes('download=1'), download.href);

        await page.screenshot({ path: path.join(OUT, 'recent-calls.png'), fullPage: true });

        // A slow answer for the old store must not paint over the new one.
        await page.setRequestInterception(true);
        page.on('request', (r) => {
            if (r.url().includes('/recent-calls/list') && r.url().includes('store=1731')) {
                setTimeout(() => r.continue().catch(() => {}), 2500);
            } else {
                r.continue().catch(() => {});
            }
        });
        await page.select('[data-rc-store]', '1731');
        await page.click('[data-rc-load]');
        await wait(200);
        await page.select('[data-rc-store]', '91');
        const cleared = await page.$eval('[data-rc-body]', (b) => b.textContent);
        check('changing store clears the previous store’s calls', cleared.includes('GREAT WALL'), cleared.trim());
        await loadCalls(page);
        await wait(3000);
        const status = await page.$eval('[data-rc-status]', (s) => s.textContent);
        check('a late answer for the previous store is ignored', status.includes('GREAT WALL') && !status.includes('Best Chinese'), status);
        await page.setRequestInterception(false);
    }


    async function layout(page) {
        // The sidebar entry: below Call recordings, linking here, and lit on this page only.
        await page.setViewport({ width: 1920, height: 1000 });
        await page.goto(BASE + '/admin/order58/recent-calls?source=fixture', { waitUntil: 'networkidle0' });
        const nav = await page.$$eval('.sidebar__link', (links) => links.map((a) => ({
            label: a.textContent.replace(/\s+/g, ' ').trim(),
            href: a.getAttribute('href'),
            active: a.classList.contains('sidebar__link--active'),
        })));
        const labels = nav.map((n) => n.label.replace(/^\S+\s/, ''));
        const at = labels.indexOf('Recent Calls');
        check('the sidebar has Recent Calls directly below Call recordings',
            at > 0 && labels[at - 1] === 'Call recordings', labels.join(' | '));
        check('it links to the page', at >= 0 && nav[at].href === '/admin/order58/recent-calls', at >= 0 ? nav[at].href : '');
        check('it is the only highlighted entry here',
            at >= 0 && nav[at].active && nav.filter((n) => n.active).length === 1);

        await page.goto(BASE + '/admin/order58/call-recordings', { waitUntil: 'networkidle0' });
        const elsewhere = await page.evaluate(() => {
            const active = Array.from(document.querySelectorAll('.sidebar__link--active')).map((a) => a.textContent.trim());
            return { active, width: document.querySelector('.content').getBoundingClientRect().width };
        });
        check('on Call recordings, Recent Calls is not highlighted',
            !elsewhere.active.some((t) => t.includes('Recent Calls')), elsewhere.active.join(' | '));

        // Width, at desktop and smaller sizes, with a full table of calls on screen.
        for (const vp of [
            { width: 1920, height: 1000, desktop: true },
            { width: 1440, height: 900, desktop: true },
            { width: 1280, height: 800, desktop: true },
            { width: 1024, height: 768, desktop: false },
            { width: 768, height: 1024, desktop: false },
            { width: 390, height: 844, desktop: false },
        ]) {
            await page.setViewport({ width: vp.width, height: vp.height });
            await page.goto(BASE + '/admin/order58/recent-calls?source=fixture', { waitUntil: 'networkidle0' });
            await loadCalls(page);
            const m = await page.evaluate(() => {
                const content = document.querySelector('.content');
                const main = document.querySelector('.main');
                const wrap = document.querySelector('.rc-table-wrap');
                const table = document.querySelector('.rc-table');
                const select = document.querySelector('[data-rc-store]').getBoundingClientRect();
                const button = document.querySelector('[data-rc-load]').getBoundingClientRect();
                const style = getComputedStyle(content);
                const doc = document.documentElement;
                const firstRow = document.querySelector('[data-rc-body] tr');
                return {
                    ratio: content.getBoundingClientRect().width / main.getBoundingClientRect().width,
                    padLeft: parseFloat(style.paddingLeft),
                    padRight: parseFloat(style.paddingRight),
                    pageScroll: doc.scrollWidth - doc.clientWidth,
                    // At least the frame's width; on a narrow screen it is wider and scrolls inside the frame.
                    tableFills: table.getBoundingClientRect().width >= wrap.clientWidth - 1,
                    wrapScroll: wrap.scrollWidth - wrap.clientWidth,
                    sameLine: Math.abs(select.bottom - button.bottom) <= 2,
                    buttonBelow: button.top >= select.bottom,
                    // Every cell of a row starts where its header starts.
                    aligned: firstRow ? Array.from(firstRow.children).every((td, i) => {
                        const th = document.querySelectorAll('.rc-table thead th')[i];
                        return Math.abs(td.getBoundingClientRect().left - th.getBoundingClientRect().left) <= 1;
                    }) : false,
                };
            });
            const tag = vp.width + 'px';
            check(tag + ' the page never scrolls sideways', m.pageScroll <= 0, m.pageScroll + 'px');
            check(tag + ' the table fills its container', m.tableFills);
            if (!vp.desktop) {
                check(tag + ' a wider table scrolls inside its own frame, not the page', m.pageScroll <= 0, m.wrapScroll + 'px inside');
            }
            check(tag + ' the seven columns line up with their headings', m.aligned);
            if (vp.desktop) {
                check(tag + ' the content uses about 95% of the area beside the sidebar',
                    m.ratio > 0.94 && m.ratio < 0.96, m.ratio.toFixed(3));
                check(tag + ' the gutters are 24-32px', m.padLeft >= 24 && m.padLeft <= 32 && m.padRight === m.padLeft,
                    m.padLeft + '/' + m.padRight);
                check(tag + ' the table needs no sideways scroll', m.wrapScroll <= 0, m.wrapScroll + 'px');
                check(tag + ' the store select and Load Calls sit on one line', m.sameLine);
            } else {
                check(tag + ' the store control and button stay usable', m.sameLine || m.buttonBelow);
            }
            await page.screenshot({ path: path.join(OUT, 'recent-calls-' + vp.width + '.png'), fullPage: true });
        }

        // Another page keeps its own column.
        await page.setViewport({ width: 1920, height: 1000 });
        await page.goto(BASE + '/admin/order58/call-recordings', { waitUntil: 'networkidle0' });
        const other = await page.evaluate(() => getComputedStyle(document.querySelector('.content')).maxWidth);
        check('other pages keep their own width', other !== '95%', other);
    }
}

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
