<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function strpos;
use function substr;

/**
 * Which channels the Manage Audio dialog shows, and why that depends on how it was opened.
 *
 * One dialog, one endpoint, one payload, two modes:
 *
 *   "+ Add audio" in a column  ->  that channel alone
 *   "Manage Audio" in Actions  ->  every channel, exactly as before
 *
 * The operator who presses "+ Add audio" in the Agent column has said which recording they are adding.
 * Showing them the other two is an invitation to upload against the wrong column, and the upload form
 * is identical in all three — so the mistake would look like success until somebody played the file.
 *
 * Nothing here changes what is fetched or what is posted. The payload is the same one request either
 * way, the form is the same form, and `ReplaceAction` still decides what a `recording_type` means.
 *
 * @see \App\AudioToText\Web\Job\Store\StoreAudioAsset the bundle this file belongs to
 */
final class ManageAudioFocusTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    private static function functionBody(string $signature): string
    {
        $script = self::script();
        $start = strpos($script, $signature);
        self::assertNotFalse($start, $signature . ' has moved.');

        $end = strpos($script, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }

    /**
     * Opened from a column, the dialog renders that channel and no other.
     *
     * Filtered on `slot.recordingType` — the SERVER's value for each slot in the payload it just sent —
     * against the mode, which came from the column the server rendered the button in. The browser
     * matches two server-supplied values; it decides neither.
     */
    public function testAddAudioShowsOnlyTheChannelItWasOpenedFrom(): void
    {
        $body = self::functionBody('function paintManage(');

        self::assertMatchesRegularExpression(
            '/manageOnly\s*===\s*null\s*\?\s*data\.slots\s*:\s*data\.slots\.filter/s',
            $body,
            'No mode means every channel; a mode means one.',
        );
        self::assertStringContainsString(
            'slot.recordingType === manageOnly',
            $body,
            'Matched on the payload\'s own value for the slot.',
        );
    }

    /**
     * The mode and the one-shot reveal are two different things, and only one is consumed.
     *
     * `manageFocus` opens the clicked channel's upload form and is spent on the first paint, so a
     * re-read after an upload does not spring it open again under the confirmation. `manageOnly` is the
     * mode and must survive that re-read — consuming it would make the other two channels appear the
     * moment an upload succeeded, which is exactly when the operator is reading a message and not
     * expecting the dialog to grow.
     */
    public function testTheModeSurvivesTheRereadThatTheRevealDoesNot(): void
    {
        $reveal = self::functionBody('function revealRequestedSlot(');

        self::assertStringContainsString('manageFocus = null;', $reveal, 'The reveal is spent.');
        self::assertStringNotContainsString(
            'manageOnly',
            $reveal,
            'The mode is not, or an upload would reveal the channels nobody asked for.',
        );
    }

    /** Both are set on every open, so neither leaks from one press to the next. */
    public function testNeitherStateLeaksBetweenOpenings(): void
    {
        $body = self::functionBody('function openManage(');

        self::assertStringContainsString(
            "manageOnly = button.getAttribute('data-a2t-manage-focus');",
            $body,
            'Assigned, not defaulted — a Manage Audio press after an Add press reads null and shows all.',
        );
        self::assertStringContainsString('manageFocus = manageOnly;', $body);
    }

    /**
     * A mode matching no slot falls back to the whole dialog rather than to an empty one.
     *
     * Only reachable if the column and the payload ever disagreed about a channel. An empty dialog
     * would be a dead end with no way forward; the full one is the answer the operator would have got
     * by pressing Manage Audio, which is never wrong.
     */
    public function testAModeThatMatchesNothingShowsEverything(): void
    {
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*slots\.length\s*===\s*0\s*\)\s*\{\s*slots\s*=\s*data\.slots;/s',
            self::functionBody('function paintManage('),
        );
    }

    /** The title says which of the two the dialog is, in the channel's own words from the payload. */
    public function testTheTitleSaysWhichModeItIs(): void
    {
        self::assertStringContainsString(
            "manageTitle.textContent = manageOnly === null ? 'Manage Audio' : 'Add audio';",
            self::functionBody('function openManage('),
        );

        self::assertStringContainsString(
            "'Add ' + slots[0].label + ' audio'",
            self::functionBody('function paintManage('),
            'Named from the payload, not from a mapping of stored values kept in the browser.',
        );
    }

    /**
     * Nothing about what is fetched or posted moved.
     *
     * The same one request builds either mode, and the upload still posts the slot's own
     * `recording_type` to the same endpoint — `ReplaceAction` is what decides what that means.
     */
    public function testNeitherModeChangesWhatIsFetchedOrPosted(): void
    {
        $body = self::functionBody('function paintManage(');

        self::assertSame(
            0,
            preg_match('/\b(load|fetch)\s*\(/', $body),
            'Painting fetches nothing: both modes render the payload already in hand.',
        );

        self::assertStringContainsString(
            "type.name = 'recording_type';",
            self::functionBody('function replaceForm('),
        );
        self::assertStringContainsString(
            'type.value = slot.recordingType',
            self::functionBody('function replaceForm('),
            'Still the slot\'s own type, whichever mode drew it.',
        );
    }
}
