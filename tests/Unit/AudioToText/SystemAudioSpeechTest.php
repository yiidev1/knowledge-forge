<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function strpos;
use function strtoupper;
use function substr;

/**
 * What the browser's own voice is allowed to do, asserted against the script itself.
 *
 * System audio has one defining property, and it is a negative one: it costs nothing. No Deepgram
 * request, no server round trip, no stored file, no database row, no worker. A regression there would
 * not fail — it would quietly start spending money on a feature sold as free, which is exactly the kind
 * of change no functional test notices. So the claim is pinned where it can be checked: the speech
 * section of the script, read as text, must contain no way of reaching the network at all.
 *
 * The other half is what it reads out. The rule is that the voice speaks the bubbles and only the
 * bubbles — not the speaker's name, not a timestamp, not a response delay, not an "edited" flag, none
 * of which anybody said out loud. That is a property of two things agreeing: the collector takes
 * `[data-a2t-text]`, and the renderer puts nothing but the wording inside it.
 *
 * @see \App\AudioToText\Web\Job\Store\StoreAudioAsset the bundle this file belongs to
 */
final class SystemAudioSpeechTest extends TestCase
{
    private const MARKER = '/* ---- System audio: the browser\'s own voice';

    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    /** The speech section alone: from its banner to the next one. */
    private static function speechSection(): string
    {
        $script = self::script();
        $start = strpos($script, self::MARKER);
        self::assertNotFalse($start, 'The system-audio section is no longer where the tests look for it.');

        $end = strpos($script, 'function renderReview', $start);
        self::assertNotFalse($end, 'The section is expected to end where the review renderer begins.');

        return substr($script, $start, $end - $start);
    }

    /**
     * Nothing in the speech path can reach a server, a provider or a store.
     *
     * Each of these is a different way the free path could stop being free: a request of any kind is a
     * cost and a dependency, a Deepgram reference is the provider this deliberately does not use, and
     * browser storage would make a session-only preference outlive the session.
     */
    public function testTheSpeechPathCannotReachTheNetworkOrAnyStore(): void
    {
        $section = self::speechSection();

        foreach ([
            'fetch(' => 'a request to our own server',
            'XMLHttpRequest' => 'an upload or a POST',
            'navigator.sendBeacon' => 'a background report',
            'Deepgram' => 'the paid provider',
            'deepgram' => 'the paid provider',
            '/ai-audio' => 'the generated-audio endpoint',
            'localStorage' => 'storage that outlives the session',
            'sessionStorage' => 'storage that outlives the session',
            'indexedDB' => 'storage that outlives the session',
        ] as $needle => $what) {
            self::assertStringNotContainsString(
                $needle,
                $section,
                'System audio must not involve ' . $what . '.',
            );
        }

        // And positively: the only thing it speaks with is the browser's own engine.
        self::assertStringContainsString('window.speechSynthesis.speak(', $section);
        self::assertStringContainsString('new window.SpeechSynthesisUtterance(', $section);
    }

    /**
     * The voice reads the wording and nothing around it.
     *
     * Asserted as the agreement between the two halves: the collector reads `[data-a2t-text]` out of
     * each `[data-a2t-turn]` and takes no other node, and the renderer puts that attribute on the
     * wording span rather than on the bubble that also holds the name, the time, the delay and the
     * edited flag. Either half drifting is what would start a synthetic voice announcing "Customer,
     * nine seconds, edited".
     */
    public function testOnlyTheWordingIsSpokenAndNotTheMetadataAroundIt(): void
    {
        $section = self::speechSection();
        $script = self::script();

        // The collector's one source.
        self::assertStringContainsString("querySelectorAll('[data-a2t-turn]')", $section);
        self::assertStringContainsString("querySelector('[data-a2t-text]')", $section);

        // And nothing else off a turn: no name, no time, no delay, no flag, no tools.
        foreach (['a2t-turn__who', 'a2t-turn__meta', 'a2t-turn__time', 'a2t-turn__delay', 'a2t-turn__flag', 'data-a2t-raw'] as $metadata) {
            self::assertStringNotContainsString(
                $metadata,
                $section,
                'The speech path must not read ' . $metadata . '.',
            );
        }

        // The renderer's side of the agreement: the attribute is on the wording span. `drawn.text` is
        // built from `turn.display || turn.text` and is a sibling of the name and the meta strip.
        self::assertStringContainsString("drawn.text.setAttribute('data-a2t-text', '')", $script);
        self::assertStringContainsString("var text = el('span', 'a2t-turn__text', turn.display || turn.text);", $script);
        // The name and the meta strip are appended to the bubble, never inside the wording span.
        self::assertStringContainsString("body.appendChild(el('span', 'a2t-turn__who', turn.label));", $script);
        self::assertStringContainsString('body.appendChild(meta);', $script);
    }

    /**
     * The gap between messages is one named value, set in one place.
     *
     * It is armed from two call sites — after an utterance ends, and again when Resume restores a gap
     * a pause interrupted — and those two must not be able to drift apart, which is the whole reason
     * for the constant. A literal creeping back into either is what this catches.
     */
    public function testTheGapIsOneNamedValueUsedEverywhereItIsArmed(): void
    {
        $section = self::speechSection();

        self::assertSame(
            1,
            preg_match('/var SYSTEM_AUDIO_GAP_MS = (\d+);/', $section, $m),
            'The gap must be declared once, beside the state it belongs to.',
        );
        self::assertSame('1500', $m[1]);

        // Both arming sites go through the name.
        self::assertSame(
            2,
            preg_match_all('/setTimeout\(speechNext, SYSTEM_AUDIO_GAP_MS\)/', $section),
            'Every gap is armed from the one value: after a line ends, and when Resume restores it.',
        );
        self::assertSame(
            0,
            preg_match_all('/setTimeout\(speechNext, \d/', $section),
            'No call site may carry its own number.',
        );
    }

    /**
     * A pause is handled by where it landed, not by what the engine says about itself.
     *
     * This is the fix for a real defect. Pausing *between* two messages used to pause the engine,
     * although nothing was being spoken — and an engine that has been paused accepts the next
     * `speak()` without ever playing it, so Resume reported "Speaking" and the reading was over.
     * `cancel()` does not lift a pause either, so a reading stopped while paused poisoned every later
     * one, in that dialog and the next.
     *
     * Hence the three assertions: the gap branch never touches the engine, the speaking branch never
     * starts a second utterance, and every teardown lifts a pause it may have left standing.
     */
    public function testAPauseIsResolvedByPhaseRatherThanByTheEnginesOwnFlags(): void
    {
        $section = self::speechSection();

        // Our own phase is what both decisions turn on.
        self::assertSame(
            1,
            preg_match('/function speechPause\(\).*?\n    \}/s', $section, $pause),
            'speechPause() is no longer where this test looks for it.',
        );
        self::assertStringContainsString("if (speech.phase === 'gap') {", $pause[0]);
        // In the gap it cancels a timer and nothing else: no engine call on that branch.
        self::assertSame(
            1,
            preg_match_all('/window\.speechSynthesis\.pause\(\)/', $pause[0]),
            'The engine is paused on exactly one branch — the one where something is being spoken.',
        );

        self::assertSame(
            1,
            preg_match('/function speechResume\(\).*?\n    \}/s', $section, $resume),
            'speechResume() is no longer where this test looks for it.',
        );
        self::assertStringContainsString("if (speech.phase === 'gap') {", $resume[0]);
        // Resuming from a gap re-arms the gap; it must never speak a line straight away, and must
        // never call speechNext() directly — that is what produced a duplicate of a half-read line.
        self::assertStringContainsString('setTimeout(speechNext, SYSTEM_AUDIO_GAP_MS)', $resume[0]);
        self::assertSame(
            0,
            preg_match_all('/^\s*speechNext\(\);/m', $resume[0]),
            'Resume never starts a line itself: the re-armed gap does.',
        );

        // And the teardown lifts a standing pause, so a stop while paused cannot silence what follows.
        self::assertSame(
            1,
            preg_match('/function speechStop\(\).*?\n    \}/s', $section, $stop),
            'speechStop() is no longer where this test looks for it.',
        );
        self::assertStringContainsString('window.speechSynthesis.cancel();', $stop[0]);
        self::assertStringContainsString('window.speechSynthesis.resume();', $stop[0]);
    }

    /**
     * The index moves once per line, and only where a line ends.
     *
     * Pause and Resume are the two operations most likely to double or lose a step, so the assertion
     * is that neither of them can: the only `speech.index++` in the file sits in `onend`.
     */
    public function testTheIndexAdvancesOnlyWhereALineEnds(): void
    {
        $section = self::speechSection();

        self::assertSame(
            1,
            preg_match_all('/speech\.index\+\+/', $section),
            'One increment, in one place.',
        );
        self::assertSame(
            1,
            preg_match('/utterance\.onend = function \(\) \{.*?speech\.index\+\+;/s', $section),
            'And that place is the end of an utterance, not a button.',
        );
    }

    /** The gap is cancellable, because a gap that outlives Stop is a voice that resumes itself. */
    public function testTheGapCanBeCancelledByPauseAndByStop(): void
    {
        $section = self::speechSection();

        // Held in state precisely so Pause and Stop can cancel it: a gap that outlives either is a
        // voice that starts talking again after the reader stopped it.
        self::assertSame(
            2,
            preg_match_all('/clearTimeout\(speech\.timer\)/', $section),
            'The gap must be cancelled by Stop and by Pause, and by nothing else.',
        );
    }

    /**
     * A browser without the API gets a sentence, not a broken panel.
     *
     * The support check tests both halves of the API, because a browser that exposes
     * `speechSynthesis` without `SpeechSynthesisUtterance` would pass a laxer check and then throw on
     * the first press.
     */
    public function testAnUnsupportingBrowserIsToldSoRatherThanGivenControls(): void
    {
        $script = self::script();

        self::assertStringContainsString("typeof window.speechSynthesis !== 'undefined'", $script);
        self::assertStringContainsString("typeof window.SpeechSynthesisUtterance === 'function'", $script);
        self::assertStringContainsString('System voice playback is not available in this browser.', $script);

        // The controls are built behind that gate, so an unsupporting browser is offered no button at
        // all rather than a button that fails.
        self::assertSame(
            1,
            preg_match(
                '/function speechControls\(\)\s*\{\s*if \(!speechSupported\) \{/',
                $script,
            ),
            'speechControls() must refuse first and build second.',
        );
    }

    /**
     * Playback state is cleared from exactly one place, and that place is called on every exit.
     *
     * Three near-identical teardowns is how a stray timer survives a closed dialog and starts reading
     * over the next recording, so the test is that there is one.
     */
    public function testEveryExitClearsPlaybackThroughTheSameTeardown(): void
    {
        $script = self::script();

        // Stop, a newly rendered transcript, a dialog opening on another recording, and a dialog
        // closing. Each of those is a point at which the turns being read cease to exist.
        self::assertGreaterThanOrEqual(
            4,
            preg_match_all('/speechStop\(\)/', $script),
            'Every way out of the dialog must go through the one teardown.',
        );
        self::assertStringContainsString('window.speechSynthesis.cancel();', $script);
    }

    /**
     * The transport is icons, and every icon is named twice.
     *
     * `iconButton()` falls back to a text label when the bank has no template of that name, so a
     * missing `<template>` does not break anything — it quietly turns the toolbar back into four word
     * buttons, which is the layout this replaced. Hence both halves are asserted: the four templates
     * exist, and the four constants they render still do.
     *
     * The names matter because these buttons have no text. `title` is for a pointer and `aria-label`
     * is the accessible name; a glyph with neither is a button only its author can use.
     */
    public function testTheTransportIsNamedIconsDrawnFromTheExistingBank(): void
    {
        $section = self::speechSection();
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AudioToText/Web/Job/Store/template.php',
        );
        $icons = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AudioToText/Web/AudioToTextIcons.php',
        );

        foreach (['play', 'pause', 'resume', 'stop'] as $name) {
            self::assertStringContainsString(
                'data-a2t-icon="' . $name . '"',
                $template,
                'The icon bank must carry a template for ' . $name . ', or the toolbar silently '
                    . 'falls back to text buttons.',
            );
            self::assertStringContainsString(
                'AudioToTextIcons::' . strtoupper($name),
                $template,
                'The ' . $name . ' template must draw the shared constant, not its own path.',
            );
        }

        // Play and Resume sit side by side, so they must not be the same glyph.
        self::assertNotSame(
            self::constantOf($icons, 'PLAY'),
            self::constantOf($icons, 'RESUME'),
            'Play and Resume are adjacent buttons; an identical glyph makes the pair a coin toss.',
        );

        // Built through the shared helper, and named for both a pointer and a screen reader.
        self::assertStringContainsString('iconButton(action.op, action.title)', $section);
        self::assertStringContainsString("button.setAttribute('aria-label', action.label)", $section);
        self::assertSame(
            4,
            preg_match_all("/\{ op: '(play|pause|resume|stop)', title: '[^']+', label: '[^']+' \}/", $section),
            'Each of the four transport buttons carries a title and an aria-label.',
        );
    }

    /** The body of one `public const NAME = '…';`, however many concatenated lines it runs to. */
    private static function constantOf(string $source, string $name): string
    {
        self::assertSame(
            1,
            preg_match('/public const ' . $name . ' = (.+?);\n/s', $source, $m),
            $name . ' is no longer declared.',
        );

        return $m[1];
    }

    /** No inline handler anywhere: the page is served under `script-src \'self\'`. */
    public function testTheScriptUsesNoInlineHandlersOrEval(): void
    {
        $script = self::script();

        foreach (['eval(', 'new Function(', 'onclick=', 'document.write'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script);
        }
    }
}
