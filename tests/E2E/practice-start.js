/*
 * Start Practice / Retry Practice, in a real browser, watching where the new tab actually goes.
 *
 * ## The bug this exists to catch
 *
 * `window.open(url, name, 'noopener')` returns **null by specification** — the opener is not meant
 * to keep a handle. Code that reads that null as "the popup was blocked" will leave a real,
 * created, `about:blank` tab sitting there while telling the reader their browser stopped it.
 * That shipped, and no markup test could have seen it: the HTML is identical either way.
 *
 * So this asserts the only thing that settles it — the URL the second tab ends up on.
 *
 *   php tests/E2E/seed-practice.php > fixtures.json
 *   KF_BASE=http://127.0.0.1:8080 node tests/E2E/practice-start.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { BASE, signIn } = require('./lib');

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));

let failures = 0;

function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function run() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
        defaultViewport: { width: 1440, height: 900 },
    });
    const page = await browser.newPage();

    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => {
        if (m.type() === 'error') errors.push(m.text());
    });

    await signIn(page, fx);
    await page.goto(BASE + fx.resultsUrl, { waitUntil: 'networkidle0' });

    const before = (await browser.pages()).length;

    /*
     * Where the tab is SENT, not only where it settles.
     *
     * The fixture's host is a subdomain of a domain with wildcard DNS, so it resolves and Order58
     * answers the demo address by redirecting an unauthenticated caller to its login page. That is
     * the provider behaving normally — but it means the final URL is not the one this application
     * built, so the first navigation is recorded as well.
     */
    let handedOver = null;
    page.on('response', async (response) => {
        if (response.url().indexOf('/demo-url') === -1) {
            return;
        }

        try {
            const body = await response.json();
            handedOver = body.url || null;
        } catch (e) {
            // A non-JSON answer is a refusal, and the assertions below say so.
        }
    });

    /*
     * The destination does not resolve — the fixture's host is deliberately unroutable, so nothing
     * leaves this machine. What is being asserted is the URL the tab was SENT to, which is the
     * thing that was broken; whether a server answers it is Order58's business, not this test's.
     */
    await page.click('.pa-retry button[type="submit"]');
    await wait(2500);

    const pages = await browser.pages();
    check('a second tab was opened', pages.length === before + 1, pages.length + ' tabs');

    const opened = pages[pages.length - 1];
    const landed = opened.url();


    // The heart of it: not about:blank, and actually the provider's address.
    check('the new tab is not left on about:blank', landed !== 'about:blank', landed);
    // On the provider, which is what this application controls. Where Order58 then sends an
    // unauthenticated caller is Order58's business.
    check('the new tab is on the Order58 host', landed.indexOf('order58.com') !== -1, landed);
    /*
     * The address this application handed over, read off the tracking response itself.
     *
     * The tab's final URL cannot prove this: Order58 redirects an unauthenticated caller to its
     * login page, so by the time the tab settles the demo address is gone from it. What the server
     * returned is the thing this application is responsible for.
     */
    check('the server handed over the demo-order address',
        handedOver !== null && handedOver.indexOf('/admin/demo/order/make/') !== -1,
        handedOver);

    // And the page that opened it is still the page it was.
    check('the original tab stays on Practice Results',
        page.url().indexOf('/demo-orders') !== -1, page.url());

    // No misleading "blocked" message when a tab was in fact created.
    const message = await page.$eval('[data-pa-retry-error]', (el) => ({
        hidden: el.hidden,
        text: el.textContent.trim(),
    })).catch(() => ({ hidden: true, text: '' }));

    check('no popup-blocked message is shown', message.hidden === true || message.text === '',
        message.text);

    // The dashboard sign-in lands on carries a pre-existing inline `style` attribute of its own,
    // which this page did not cause and does not own.
    const mine = errors.filter((e) => e.indexOf('Content Security Policy') === -1);
    check('no page errors', mine.length === 0, mine.slice(0, 2).join(' | '));

    await browser.close();
    console.log(failures === 0 ? 'ALL PASS' : failures + ' FAILED');
    process.exit(failures === 0 ? 0 : 1);
}

run().catch((e) => {
    console.error(e);
    process.exit(1);
});
