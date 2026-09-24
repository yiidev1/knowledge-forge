/* The three ways to hear one recording, inside the Details dialog. */
const { BASE, launch, signIn, sleep } = require('./lib');
const fx = require('./fixtures.json');

const R = '.a2t-review-dialog';
let failures = 0;
function check(name, ok, detail) {
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (detail === undefined ? '' : '   [' + detail + ']'));
    if (!ok) failures++;
}

async function openDetails(page, label) {
    await page.goto(BASE + '/audio-to-text/store/' + fx.store, { waitUntil: 'networkidle0' });
    await page.click('[data-a2t-details-label="' + label + '"]');
    await page.waitForSelector(R + ' [data-a2t-turn]', { timeout: 5000 });
    await sleep(400);
}

const rows = (page) => page.$$eval(R + ' [data-a2t-listen] .a2t-listen__label', (n) =>
    n.map((x) => x.textContent.trim()));

(async () => {
    const { browser, page, errors } = await launch();
    // The browser's speech engine is not present in headless Chrome, so it is stubbed: what is under
    // test is the ordering, the gap and the teardown, not the operating system's voices.
    await page.evaluateOnNewDocument(() => {
        window.__spoken = [];
        window.__cancels = 0;
        window.SpeechSynthesisUtterance = function (text) { this.text = text; };
        // Modelled on Chrome rather than on convenience, because the three quirks below are the whole
        // reason Resume could fail and a stub that "just speaks" proves nothing:
        //   1. `speak()` while the engine is paused QUEUES the utterance and never plays it.
        //   2. `cancel()` empties the queue but does NOT lift a pause.
        //   3. `pause()` mid-utterance holds it; `speaking` stays true.
        const fake = {
            speaking: false, paused: false,
            queue: [], current: null,
            speak(u) { fake.queue.push(u); fake.pump(); },
            pump() {
                if (fake.paused || fake.speaking || fake.queue.length === 0) return;
                const u = fake.queue.shift();
                window.__spoken.push({ text: u.text, at: Date.now(), rate: u.rate });
                fake.speaking = true;
                fake.current = u;
                fake.arm(u);
            },
            // Finish on a short timer, the way a very short utterance would.
            arm(u) { u.timer = setTimeout(() => {
                fake.speaking = false; fake.current = null;
                if (u.onend) u.onend();
            }, 40); },
            cancel() {
                window.__cancels++;
                if (fake.current) clearTimeout(fake.current.timer);
                fake.current = null;
                fake.queue = [];
                fake.speaking = false;
                // Deliberately NOT `fake.paused = false` — Chrome leaves the pause standing.
            },
            pause() {
                fake.paused = true;
                if (fake.current) { clearTimeout(fake.current.timer); fake.current.held = true; }
            },
            resume() {
                fake.paused = false;
                if (fake.current && fake.current.held) {
                    fake.current.held = false;
                    fake.arm(fake.current);
                    return;
                }
                fake.pump();
            },
            getVoices: () => [],
        };
        Object.defineProperty(window, 'speechSynthesis', { configurable: true, get: () => fake });
    });
    await signIn(page, fx);

    console.log('\nA. THE PANEL');
    await openDetails(page, 'Common / Mixed');
    check('panel visible', await page.$eval(R + ' [data-a2t-listen]', (n) => !n.hidden));
    check('three labelled groups', JSON.stringify(await rows(page))
        === JSON.stringify(['Original', 'AI audio', 'System']),
        JSON.stringify(await rows(page)));
    // One toolbar, on one line: the three groups must share a row, not stack. Compared with a small
    // tolerance because each group is centred on its own tallest control, so their tops differ by a
    // pixel — a stacked layout differs by forty.
    const tops = await page.$$eval(R + ' [data-a2t-listen] .a2t-listen__group',
        (n) => n.map((g) => Math.round(g.getBoundingClientRect().top)));
    check('all three groups on one row',
        tops.length === 3 && Math.max(...tops) - Math.min(...tops) <= 3, JSON.stringify(tops));
    const barHeight = await page.$eval(R + ' [data-a2t-listen]',
        (n) => Math.round(n.getBoundingClientRect().height));
    check('the toolbar is compact', barHeight <= 64, barHeight + 'px');
    // Icon-only transport, still named for a screen reader and for a pointer.
    const named = await page.$$eval(R + ' [data-a2t-speak]', (n) => n.map(
        (b) => [b.getAttribute('aria-label'), b.title, !!b.querySelector('svg')]));
    check('every transport button is an icon with two names',
        named.length === 4 && named.every((x) => x[0] && x[1] && x[2]), JSON.stringify(named));
    const src = await page.$eval(R + ' [data-a2t-listen] audio', (a) => a.getAttribute('src'));
    check('original player points at the guarded route', /\/original\/file$/.test(src), src);
    check('preload is metadata', await page.$eval(R + ' [data-a2t-listen] audio', (a) => a.preload) === 'metadata');
    check('speech controls present', (await page.$$(R + ' [data-a2t-speak]')).length === 4);


    console.log('\nA2. IT REFLOWS RATHER THAN OVERFLOWING');
    // The divider is drawn by an adjacent-sibling rule, so a narrower layout has to *beat* that rule
    // to drop it. A one-class override silently loses to it, leaving a vertical line hanging at the
    // start of a full-width row — which is what this measures, not the widths themselves.
    const layout = async () => page.$$eval(R + ' [data-a2t-listen] .a2t-listen__group', (n) => n.map((g) => ({
        label: g.querySelector('.a2t-listen__label').textContent.trim(),
        border: getComputedStyle(g).borderLeftWidth,
        top: Math.round(g.getBoundingClientRect().top),
        width: Math.round(g.getBoundingClientRect().width),
    })));

    await page.setViewport({ width: 1024, height: 900 });
    await openDetails(page, 'Common / Mixed');
    const mid = await layout();
    check('medium drops the voice to its own row', mid[2].top > mid[0].top + 10, JSON.stringify(mid.map((g) => g.top)));
    check('and it carries no stray divider', mid[2].border === '0px', mid[2].border);
    check('the players still share the first row', Math.abs(mid[0].top - mid[1].top) <= 3);

    await page.setViewport({ width: 520, height: 900 });
    await openDetails(page, 'Common / Mixed');
    const narrow = await layout();
    check('small stacks all three', new Set(narrow.map((g) => g.top)).size === 3,
        JSON.stringify(narrow.map((g) => g.top)));
    check('with no dividers at all', narrow.every((g) => g.border === '0px'));
    // A percentage width inside a min-width:0 flex row resolves to zero; a 0px player is present,
    // focusable and unusable.
    const narrowPlayer = await page.$eval(R + ' [data-a2t-listen] audio',
        (a) => Math.round(a.getBoundingClientRect().width));
    check('the player is still a usable size', narrowPlayer >= 170, narrowPlayer + 'px');

    await page.setViewport({ width: 1500, height: 1000 });
    await openDetails(page, 'Common / Mixed');

    console.log('\nB. IT READS THE TRANSCRIPT, IN ORDER');
    const onScreen = await page.$$eval(R + ' [data-a2t-turn] [data-a2t-text]', (n) =>
        n.map((x) => x.textContent.trim()));
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(5000);
    let spoken = await page.evaluate(() => window.__spoken.map((s) => s.text));
    check('first message spoken', spoken[0] === onScreen[0], spoken[0]);
    check('several messages spoken', spoken.length >= 3, spoken.length + ' spoken');
    check('in visible order', onScreen.slice(0, spoken.length).join('|') === spoken.join('|'),
        spoken.length + ' of ' + onScreen.length);
    check('no metadata spoken', !spoken.some((t) => /\d\d:\d\d|response|Version/.test(t)),
        JSON.stringify(spoken.slice(0, 2)));

    console.log('\nC. THE GAP');
    const gaps = await page.evaluate(() => window.__spoken.slice(1).map((s, i) => s.at - window.__spoken[i].at));
    check('the configured gap between messages', gaps.every((g) => g > 1350 && g < 1950),
        JSON.stringify(gaps));

    console.log('\nD. HIGHLIGHT');
    check('the spoken turn is marked', (await page.$$(R + ' .a2t-turn--speaking')).length === 1);

    console.log('\nE. PAUSE DURING THE GAP');
    // Land inside a gap: a message ends 40ms after it starts, so ~1s later is mid-gap.
    await page.click(R + ' [data-a2t-speak="stop"]');
    await page.evaluate(() => { window.__spoken = []; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(300);
    await page.click(R + ' [data-a2t-speak="pause"]');
    const atPause = await page.evaluate(() => window.__spoken.length);
    await sleep(2500);
    check('nothing new was spoken while paused',
        (await page.evaluate(() => window.__spoken.length)) === atPause, 'was ' + atPause);
    check('status says Paused', (await page.$eval(R + ' [data-a2t-listen] [role=status]', (n) => n.textContent)) === 'Paused');
    await page.click(R + ' [data-a2t-speak="resume"]');
    // Resume re-arms the whole gap rather than speaking at once, so this waits out a full one.
    await sleep(1900);
    check('resume continues', (await page.evaluate(() => window.__spoken.length)) > atPause);

    console.log('\nE2. PAUSE AND RESUME, IN BOTH PLACES A PAUSE CAN LAND');
    // The regression this section exists for: pausing between two messages used to pause the whole
    // engine, and a `speak()` issued to a paused engine is queued and never played — so Resume said
    // "Speaking" and nothing was ever spoken again.
    const spokenNow = () => page.evaluate(() => window.__spoken.map((s) => s.text));
    const status = () => page.$eval(R + ' [data-a2t-listen] [role=status]', (n) => n.textContent);
    const restart = async () => {
        await page.click(R + ' [data-a2t-speak="stop"]');
        await page.evaluate(() => { window.__spoken = []; });
        await page.click(R + ' [data-a2t-speak="play"]');
    };

    await openDetails(page, 'Common / Mixed');
    const lines = await page.$$eval(R + ' [data-a2t-turn] [data-a2t-text]', (n) =>
        n.map((x) => x.textContent.trim()));

    // --- paused between messages ---------------------------------------------------------------
    await restart();
    await sleep(300);                       // message 1 done (40ms), now inside the gap
    await page.click(R + ' [data-a2t-speak="pause"]');
    check('1. play started the first message', (await spokenNow())[0] === lines[0], (await spokenNow())[0]);
    check('5. pausing in the gap cancels the pending timer', (await spokenNow()).length === 1);
    check('   and the status says Paused', (await status()) === 'Paused');
    await sleep(3000);
    check('6. waiting far longer than the gap still starts nothing',
        (await spokenNow()).length === 1, JSON.stringify(await spokenNow()));

    await page.click(R + ' [data-a2t-speak="resume"]');
    check('7. resume re-arms the gap rather than speaking at once', (await spokenNow()).length === 1);
    // Longer than the gap it re-armed — the point being that it re-arms the whole gap rather than
    // speaking the moment Resume is pressed.
    await sleep(1900);
    let after = await spokenNow();
    check('8. and after the gap exactly the next message starts',
        after.length === 2 && after[1] === lines[1], JSON.stringify(after));
    check('9. with no duplicate of the line already read', after[0] !== after[1]);
    check('10. and nothing skipped', after.join('|') === lines.slice(0, 2).join('|'));

    // --- paused mid-utterance ------------------------------------------------------------------
    await restart();
    await page.click(R + ' [data-a2t-speak="pause"]');   // within the 40ms the line is "speaking"
    check('2. pausing mid-line does not advance', (await spokenNow()).length === 1);
    check('   and the status says Paused', (await status()) === 'Paused');
    await sleep(2500);
    check('   a held utterance does not finish on its own', (await spokenNow()).length === 1);
    await page.click(R + ' [data-a2t-speak="resume"]');
    check('3. resume continues the same line, starting no second one',
        (await spokenNow()).length === 1, JSON.stringify(await spokenNow()));
    await sleep(1800);
    after = await spokenNow();
    check('4. the held line then finishes and the gap runs',
        after.length === 2 && after[1] === lines[1], JSON.stringify(after));

    // --- repeated cycles -----------------------------------------------------------------------
    await restart();
    for (let i = 0; i < 2; i++) {
        await sleep(300);
        await page.click(R + ' [data-a2t-speak="pause"]');
        await sleep(400);
        await page.click(R + ' [data-a2t-speak="resume"]');
    }
    await sleep(4000);
    after = await spokenNow();
    check('11. pause/resume twice over reads on without repeating or skipping',
        after.length >= 3 && after.join('|') === lines.slice(0, after.length).join('|'),
        JSON.stringify(after));

    // --- stop while paused, then play again ----------------------------------------------------
    await restart();
    await sleep(300);
    await page.click(R + ' [data-a2t-speak="pause"]');
    await page.click(R + ' [data-a2t-speak="stop"]');
    check('12. stop from paused resets the status', (await status()) === 'Ready');
    check('    and clears the highlight', (await page.$$(R + ' .a2t-turn--speaking')).length === 0);
    await page.evaluate(() => { window.__spoken = []; });
    await sleep(2200);
    check('17. leaving no orphan timer', (await spokenNow()).length === 0);
    // The engine must not have been left paused by the stop, or this play is silent.
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(400);
    after = await spokenNow();
    check('13. play after a paused stop starts cleanly at the first message',
        after.length === 1 && after[0] === lines[0], JSON.stringify(after));

    // --- closing and reopening while paused ----------------------------------------------------
    await restart();
    await sleep(300);
    await page.click(R + ' [data-a2t-speak="pause"]');
    await page.click(R + ' [data-a2t-dialog-close]');
    await sleep(200);
    await openDetails(page, 'Common / Mixed');
    await page.evaluate(() => { window.__spoken = []; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(400);
    check('14/15. a dialog closed while paused leaves the engine able to speak again',
        (await spokenNow()).length === 1, JSON.stringify(await spokenNow()));

    // --- a finished reading can be started again -----------------------------------------------
    await page.click(R + ' [data-a2t-speak="stop"]');
    await page.evaluate(() => { window.__spoken = []; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(lines.length * 1700 + 1500);
    check('   playback reaches the end', (await status()) === 'Finished', await status());
    await page.evaluate(() => { window.__spoken = []; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(400);
    check('16. and a finished reading starts again from the top',
        (await spokenNow())[0] === lines[0], JSON.stringify(await spokenNow()));
    await page.click(R + ' [data-a2t-speak="stop"]');

    console.log('\nF. STOP AND TEARDOWN');
    await page.click(R + ' [data-a2t-speak="stop"]');
    await sleep(200);
    check('speech cancelled', (await page.evaluate(() => window.__cancels)) > 0);
    check('highlight cleared', (await page.$$(R + ' .a2t-turn--speaking')).length === 0);
    check('status back to Ready', (await page.$eval(R + ' [data-a2t-listen] [role=status]', (n) => n.textContent)) === 'Ready');
    await page.evaluate(() => { window.__spoken = []; });
    await sleep(2600);
    check('no stray timer fired after stop', (await page.evaluate(() => window.__spoken.length)) === 0);

    console.log('\nG. CLOSING AND SWITCHING');
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(300);
    await page.click(R + ' [data-a2t-dialog-close]');
    await sleep(200);
    await page.evaluate(() => { window.__spoken = []; });
    await sleep(2600);
    check('closing stops the voice', (await page.evaluate(() => window.__spoken.length)) === 0);

    await openDetails(page, 'Common / Mixed');
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(300);
    await openDetails(page, 'Caller');
    await page.evaluate(() => { window.__spoken = []; });
    await sleep(2600);
    check('switching modal stops the previous voice',
        (await page.evaluate(() => window.__spoken.length)) === 0);

    console.log('\nH. A ONE-SIDED RECORDING READS ONLY ITS OWN MESSAGES');
    const callerOnScreen = await page.$$eval(R + ' [data-a2t-turn] [data-a2t-text]', (n) =>
        n.map((x) => x.textContent.trim()));
    await page.evaluate(() => { window.__spoken = []; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(700);
    spoken = await page.evaluate(() => window.__spoken.map((s) => s.text));
    check('speaks this recording only', spoken.every((t) => callerOnScreen.includes(t)),
        JSON.stringify(spoken.slice(0, 2)));
    check('caller original player present',
        /caller|\/original\/file$/.test(await page.$eval(R + ' [data-a2t-listen] audio', (a) => a.getAttribute('src'))));

    console.log('\nI. TWO SESSIONS CANNOT OVERLAP');
    await page.click(R + ' [data-a2t-speak="stop"]');
    await page.evaluate(() => { window.__spoken = []; window.__cancels = 0; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(300);
    // Counted from here, so the first press's own defensive cancel is not mistaken for the second's.
    await page.evaluate(() => { window.__cancels = 0; });
    await page.click(R + ' [data-a2t-speak="play"]');
    await sleep(5000);
    spoken = await page.evaluate(() => window.__spoken.map((s) => s.text));
    // A second press is ignored, so the queue neither doubles nor restarts: each message once.
    check('no message spoken twice', spoken.length === new Set(spoken).size, JSON.stringify(spoken));
    check('and it is not cancelled by the second press',
        (await page.evaluate(() => window.__cancels)) === 0,
        await page.evaluate(() => String(window.__cancels)));

    console.log('\nJS errors: ' + (errors.length ? JSON.stringify(errors.slice(0, 4)) : 'none'));
    console.log('\n' + (failures === 0 ? 'ALL PASS' : failures + ' FAILURE(S)'));
    await browser.close();
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error('HARNESS FAILED:', e.stack); process.exit(2); });
