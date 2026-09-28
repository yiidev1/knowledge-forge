<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ProcessingStage;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function in_array;
use function preg_match;
use function preg_match_all;
use function sort;
use function strpos;
use function substr;

/**
 * What the Update Audio dialog may say about a replacement's progress.
 *
 * The dialog follows a job it queued a moment ago, and the temptation with a progress display is to make
 * it look busier than the server is: a percentage, a moving bar, an estimate. The status endpoint
 * publishes a status and a stage and knows nothing else — no duration, no proportion, nothing that could
 * honestly be turned into a number. A bar would therefore be invented, and an invented bar that sticks
 * at some figure while a 400-second transcription runs teaches an administrator to distrust the one
 * screen that is telling them the truth.
 *
 * So two claims are pinned here, neither of which a functional test would notice breaking:
 *
 * 1. Every stage the dialog can be shown has words of its own, and they are the enum's own values — so a
 *    stage added to {@see ProcessingStage} cannot silently show as a generic "Working…".
 * 2. Nothing in that section computes a percentage.
 *
 * The page's own upload form, further up the same file, genuinely does report upload percentages — it is
 * measuring bytes it is itself sending, which is a real fraction of a known total. That is why these
 * assertions read one named section rather than the whole script.
 *
 * @see \App\AudioToText\Web\Job\Store\StoreAudioAsset the bundle this file belongs to
 */
final class UpdateAudioProgressTest extends TestCase
{
    private const SECTION_START = '/* ---- Updating this recording\'s audio';
    private const SECTION_END = '/* ---- Generating this recording\'s AI audio';

    /**
     * The two stages that are not progress but an outcome.
     *
     * Deliberately absent from the label map: the watcher stops on each and says something the map could
     * not — one closes the dialog and reloads onto the new recording, the other explains where to look
     * for the reason. A label for them would be dead code that looked like the live path.
     */
    private const TERMINAL = ['COMPLETED', 'FAILED'];

    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    /** The update section alone: from its banner to the next one. */
    private static function updateSection(): string
    {
        $script = self::script();
        $start = strpos($script, self::SECTION_START);
        self::assertNotFalse($start, 'The Update Audio section is no longer where the tests look for it.');

        $end = strpos($script, self::SECTION_END, $start);
        self::assertNotFalse($end, 'The section is expected to end where the generation section begins.');

        return substr($script, $start, $end - $start);
    }

    /**
     * The shared label map names every stage a job can be in, and invents none.
     *
     * Both directions matter. A missing stage is a dialog that goes quiet halfway through a
     * transcription; an extra one is a stage this application believes in and the worker never writes,
     * which is a label nobody will ever see being wrong.
     */
    public function testEveryProcessingStageHasWordsOfItsOwn(): void
    {
        $script = self::script();

        $start = strpos($script, 'window.KFAudioStages = {');
        self::assertNotFalse($start, 'The one stage vocabulary for this page has moved.');

        $end = strpos($script, '};', $start);
        self::assertNotFalse($end);

        preg_match_all('/^\s{4}([A-Z_]+):/m', substr($script, $start, $end - $start), $found);

        $labelled = $found[1];
        sort($labelled);

        $expected = [];

        foreach (ProcessingStage::cases() as $stage) {
            if (!in_array($stage->value, self::TERMINAL, true)) {
                $expected[] = $stage->value;
            }
        }

        sort($expected);

        self::assertSame(
            $expected,
            $labelled,
            'The dialog shows stage names from the enum, and only stage names from the enum.',
        );
    }

    /** The two outcomes are handled where they can say more than a stage name would. */
    public function testTheTerminalStatesAreActedOnRatherThanLabelled(): void
    {
        $section = self::updateSection();

        foreach (self::TERMINAL as $status) {
            self::assertStringContainsString(
                "state.status === '" . $status . "'",
                $section,
                $status . ' is a branch in the watcher, not a row in the label map.',
            );
        }
    }

    /**
     * No percentage is computed for a job whose duration the server does not publish.
     *
     * `Math.round` and a literal `%` are the two shapes this takes; a width or a `<progress>` value is
     * the same claim expressed in markup.
     */
    public function testTheReplacementWatcherInventsNoProgressNumber(): void
    {
        $section = self::updateSection();

        foreach ([
            'Math.round(' => 'a percentage worked out from something',
            "'%'" => 'a percentage printed',
            '.style.width' => 'a bar drawn to a made-up length',
            'progress.value' => 'a native progress element given a position',
        ] as $fragment => $what) {
            self::assertStringNotContainsString(
                $fragment,
                $section,
                'The Update dialog must not show ' . $what . ': the status endpoint publishes no duration.',
            );
        }
    }

    /**
     * Progress is read from the job that was created, never from the one the dialog was opened on.
     *
     * A replacement is a new recording with a new public id. Watching the old one would report a
     * recording that finished long ago, so the dialog would announce success within one poll — the worst
     * possible failure here, because it is indistinguishable from working.
     */
    public function testTheDialogWatchesTheUrlTheServerReturned(): void
    {
        $section = self::updateSection();

        self::assertMatchesRegularExpression(
            '/watchReplacement\(\s*data\.statusUrl/',
            $section,
            'The poll target is the server-generated URL from the upload response.',
        );

        self::assertSame(
            0,
            preg_match('/statusUrl\s*=\s*[\'"]/', $section),
            'The browser does not compose a status URL of its own.',
        );
    }

    /** Words, not a guess, when a stage arrives that this page has no label for. */
    public function testAnUnlabelledStageStillSaysSomething(): void
    {
        self::assertStringContainsString("|| 'Working…'", self::updateSection());
    }
}
