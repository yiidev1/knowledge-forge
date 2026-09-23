const puppeteer = require('puppeteer-core');

const BASE = process.env.KF_BASE || 'http://127.0.0.1:8080';

async function launch() {
    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/google-chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--window-size=1500,1000'],
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
    const csp = [];
    await page.evaluateOnNewDocument(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => {
            window.__csp.push(e.violatedDirective + ' @ ' + e.sourceFile + ':' + e.lineNumber);
        });
    });
    return { browser, page, errors, csp };
}

async function signIn(page, fx) {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('input[name="username"]', fx.admin);
    await page.type('input[name="password"]', fx.password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('form button[type="submit"], form input[type="submit"]'),
    ]);
}

/** Highlight the words of one turn, the way an administrator does with the cursor. */
async function selectTurnText(page, rootSel, index) {
    return page.evaluate((rootSel, index) => {
        const root = document.querySelector(rootSel);
        const turn = root.querySelector('[data-a2t-turn="' + index + '"]');
        if (!turn) return false;
        const body = turn.querySelector('[data-a2t-text]');
        // Start inside the text node, the way a cursor drag does — selectNodeContents() would put
        // startContainer on the element and the selection rule reads its parent.
        const node = body.firstChild;
        const range = document.createRange();
        range.setStart(node, 0);
        range.setEnd(node, node.length);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
        return true;
    }, rootSel, index);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

module.exports = { BASE, launch, signIn, selectTurnText, sleep };
