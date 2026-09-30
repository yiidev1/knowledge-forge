<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\SharedConversationReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function dirname;
use function file_get_contents;
use function preg_match;
use function str_contains;

/**
 * Where a borrowed conversation may appear, and what it may never touch.
 *
 * Borrowing is a presentation decision, and the risk in one is that it spreads: a screen that starts
 * showing the call's words where it used to show the recording's own is a screen that has quietly
 * changed what it is for. Four surfaces must keep showing the recording's own transcript whatever the
 * call says, and each of them has a different reason.
 *
 * These are read on the source, which is unusual and deliberate: the claims are about what a class does
 * **not** do, and the only way to fail such a test by accident is to write the line it forbids.
 */
final class DerivedViewBoundariesTest extends TestCase
{
    private static function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/AudioToText/' . $relative);
    }

    /**
     * 13. The Original transcript page never derives.
     *
     * It is the machine's own record and the evidence a correction is judged against. Showing the call's
     * corrected words there would leave nothing in the application that says what was actually heard.
     */
    public function testTheOriginalTranscriptPageNeverDerives(): void
    {
        $source = self::source('Web/Job/Original/Action.php');

        self::assertStringNotContainsString('ConversationPresenter', $source);
        self::assertStringNotContainsString('SharedConversationReader', $source);
        self::assertStringContainsString(
            'MachineConversationReader',
            $source,
            'It reads the machine layer, which is what makes it immune by construction.',
        );
    }

    /**
     * 12. The revision history stays this recording's own.
     *
     * An administrator looking at what was corrected on the Callee file must see what was corrected on
     * the Callee file. Its own `reviewed_segments` and its own audit rows are untouched by any of this
     * and remain the only thing that screen reads.
     */
    public function testTheRevisionHistoryStaysTheRecordingsOwn(): void
    {
        $source = self::source('Web/Job/Review/History/Action.php');

        self::assertStringNotContainsString('ConversationPresenter', $source);
        self::assertStringNotContainsString('SharedConversationReader', $source);
    }

    /**
     * 14. AI audio speaks the words in the file it is generated from.
     *
     * A Callee recording's clean audio has to match the Callee recording. Feeding it the call's mixed
     * conversation would generate audio saying things that are not on that recording — and the pairing
     * of audio to transcript is the whole product there.
     */
    public function testAiAudioStillUsesTheRecordingsOwnWords(): void
    {
        foreach (['Application/Tts/TtsScriptBuilder.php', 'Web/Conversion/AiAudio/Action.php'] as $file) {
            $source = self::source($file);

            self::assertStringNotContainsString('ConversationPresenter', $source, $file);
            self::assertStringNotContainsString('SharedConversationReader', $source, $file);
        }
    }

    /**
     * 4 / 6 / 12. Borrowing is a read. Nothing in the reader writes anywhere.
     *
     * The machine transcript, the channel recording's reviewed layer and its revision trail are all
     * safe for the same reason: there is no write to reach them through. Asserted on the reader's
     * dependencies, because a repository it does not hold is one it cannot call.
     */
    public function testTheReaderHoldsNothingItCouldWriteThrough(): void
    {
        $source = self::source('Application/SharedConversationReader.php');

        foreach ([
            'saveReview',
            'clearReview',
            'recordCallSession',
            'revisions',
            'transaction',
            'ReviewConversationService',
            'SegmentRevisionRepository',
        ] as $forbidden) {
            self::assertFalse(
                str_contains($source, $forbidden),
                $forbidden . ' would give the reader a way to write.',
            );
        }

        // Three collaborators, all of them readers.
        $constructor = (new ReflectionClass(SharedConversationReader::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertCount(3, $constructor->getParameters());
    }

    /**
     * The borrowed turns carry no correction controls, and the payload says so per row.
     *
     * `canEdit` mirrors the `canMove` pattern already in the dialog: the server answers, the browser
     * does not guess. A pencil on a borrowed turn would post an edit to the job being *looked at*, which
     * is not the job the words came from.
     */
    public function testBorrowedTurnsOfferNoCorrectionControls(): void
    {
        $fragment = self::source('Web/Job/Review/Fragment/Action.php');

        self::assertStringContainsString("'canEdit' => false,", $fragment, 'Borrowed rows withhold it.');
        self::assertStringContainsString("'canEdit' => true,", $fragment, 'The recording\'s own rows keep it.');
        self::assertStringContainsString("'text' => null", $fragment, 'And carry no endpoint to post to.');

        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');

        self::assertSame(
            1,
            preg_match('/if \(turn\.canEdit\) \{\s*var edit = iconButton\(\'edit\'/', $script),
            'The dialog draws the pencil only where the server allows one.',
        );
    }

    /**
     * 15. The importer hands the provider's own call session id across the seam.
     *
     * It is the only writer of that column — nothing derives it from a browser, an order or a filename
     * at ingest time — and it already holds the value, because the import item is keyed on it.
     */
    public function testTheImporterPassesTheCallSessionAcrossTheSeam(): void
    {
        $processor = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Order58/Application/RecordingImportProcessor.php',
        );

        self::assertSame(
            1,
            preg_match('/ingestFile\((?:[^;]*?)\$item->callSessionId,/s', $processor),
            'The session id reaches ingestFile.',
        );

        $port = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Shared/Audio/AudioIngestionPortInterface.php',
        );

        self::assertStringContainsString(
            '?string $callSessionId = null,',
            $port,
            'Defaulted, so the manual upload path compiles and records none.',
        );
    }

    /**
     * A borrowed conversation offers no editable rows at all, so nothing can be posted from the page.
     *
     * Belt and braces with the row flags above: `ReviewPageView` empties the turn list, so the page's
     * own `foreach` produces nothing even if a template forgot to branch.
     */
    public function testTheReviewPageOffersNoEditableRowsWhileBorrowing(): void
    {
        self::assertStringContainsString(
            '$derived === null ? $views : []',
            self::source('Web/Job/Review/ReviewPageView.php'),
        );
    }
}
