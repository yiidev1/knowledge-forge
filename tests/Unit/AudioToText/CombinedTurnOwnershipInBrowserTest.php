<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function str_contains;
use function strlen;
use function strpos;
use function substr;
use function substr_count;

/**
 * The browser half of the owner-address rule, asserted against the two scripts themselves.
 *
 * ## Why this is a test and not a comment
 *
 * A combined conversation puts two recordings' messages in one thread. Both number their own messages
 * from zero, so the thread contains `data-a2t-turn="1"` twice, and the bubble above a message is very
 * often the **other** recording's. Two things in the browser depend on getting that right:
 *
 *  1. the merge neighbour lookup, which finds the message that would actually be joined;
 *  2. the version each correction locks against, which belongs to the recording that owns the message.
 *
 * Get either wrong and the failure is silent: a correction aimed at one sentence is written over a
 * different one, in the other speaker's words, with no error anywhere. Nothing functional notices,
 * because every request involved is well-formed and every response is a success. So the rule is pinned
 * where a regression would have to pass through: the source of the two functions that implement it.
 *
 * It is deliberately a text assertion. There is no JavaScript test runner in this project, and the
 * alternative — asserting nothing — is how the single most dangerous behaviour in this feature would
 * end up protected by a docblock.
 *
 * @see \App\AudioToText\Domain\Speaker\CombinedTurn the server half of the same rule
 */
final class CombinedTurnOwnershipInBrowserTest extends TestCase
{
    private static function sharedModule(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');
    }

    private static function dialog(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    /**
     * The neighbour lookup narrows by owner before it narrows by index.
     *
     * Both halves are required. An unscoped query would find the other recording's message of the same
     * index — and `querySelector` returns the first match in document order, so which one it found
     * would depend on how the call happened to interleave.
     */
    public function testTheNeighbourLookupIsScopedByOwner(): void
    {
        $body = self::functionBody(self::sharedModule(), 'function neighbourOf(');

        self::assertNotSame('', $body, 'neighbourOf must still exist to be scoped');
        self::assertTrue(
            str_contains($body, "data-a2t-owner"),
            'neighbourOf must read the owner before searching for a neighbour',
        );
        self::assertTrue(
            str_contains($body, '[data-a2t-owner="\' + owner + \'"][data-a2t-turn="\' + wanted + \'"]'),
            'the scoped query must require the owner AND the index, not either alone',
        );
    }

    /** And it still behaves exactly as it did on a page that has only one recording. */
    public function testTheNeighbourLookupIsUnchangedWithoutAnOwner(): void
    {
        $body = self::functionBody(self::sharedModule(), 'function neighbourOf(');

        self::assertTrue(
            str_contains($body, "if (owner === null) {"),
            'a thread with no owners must keep the plain lookup, so the correction page is untouched',
        );
    }

    /**
     * A correction sends the version of the message's own recording, when there is one.
     *
     * `version` — the one the dialog last read — remains the answer for a single recording. What must
     * not happen is a combined thread sending that number, because it describes the mixed row, which
     * holds no words and whose counter locks nothing.
     */
    public function testACorrectionPrefersTheOwnersVersion(): void
    {
        $body = self::functionBody(self::dialog(), 'function correct(');

        self::assertTrue(
            str_contains($body, 'ownerVersion'),
            'correct() must accept the owning recording’s version',
        );
        self::assertTrue(
            str_contains($body, "body.set('expected_review_count', String(expected));"),
            'the request must carry the resolved version, never the module-level one directly',
        );
    }

    /**
     * An unresolvable version refuses rather than guessing.
     *
     * This is what makes the projected payload's `-1` safe: anything reaching for a conversation-level
     * version in a combined thread is stopped here instead of locking against the wrong row.
     */
    public function testAnUnresolvableVersionSendsNothing(): void
    {
        $body = self::functionBody(self::dialog(), 'function correct(');

        self::assertTrue(
            str_contains($body, '!isFinite(expected) || expected < 0'),
            'a missing or negative version must refuse the request outright',
        );
    }

    /** Each rendered message carries its owner and its owner's version, or neither. */
    public function testEachRenderedTurnCarriesItsOwnerAndVersion(): void
    {
        $body = self::functionBody(self::dialog(), 'function reviewTurn(');

        self::assertTrue(
            str_contains($body, "row.setAttribute('data-a2t-owner', turn.owner);"),
            'the owner must be stamped on the row the neighbour lookup will read',
        );
        self::assertTrue(
            str_contains($body, "row.setAttribute('data-a2t-version', String(turn.version));"),
            'the owner’s version must be stamped on the row a correction will read',
        );
    }

    /**
     * The two confirmations carry the version of the row they were opened on.
     *
     * They live in dialogs outside the thread, so by the time one is submitted there is no row to ask —
     * which is why the version is copied onto the form at the moment it opens.
     */
    public function testTheConfirmationsAreStampedWithTheRowsVersion(): void
    {
        $module = self::sharedModule();

        self::assertSame(
            2,
            substr_count($module, "parts.form.setAttribute('data-a2t-version'"),
            'both the move and the merge confirmation must be stamped',
        );
    }

    /** And a submitted form resolves the version from its row, falling back to its own stamp. */
    public function testASubmittedFormResolvesTheOwningRowsVersion(): void
    {
        $body = self::functionBody(self::dialog(), 'function submitForm(');

        self::assertTrue(
            str_contains($body, "form.closest('[data-a2t-turn]')"),
            'the inline editor must take the version from the message it sits in',
        );
        self::assertTrue(
            str_contains($body, "form.getAttribute('data-a2t-version')"),
            'a form outside the thread must fall back to the stamp it was opened with',
        );
    }

    /**
     * The upload form's transcription settings follow the chosen type, and nothing else does.
     *
     * The script hides two fields and swaps a button label. It must not do anything that looks like
     * enforcement — the server decides, and a browser that never ran this still cannot get a mixed
     * recording transcribed. What is pinned here is that the rule the page shows is the same one the
     * policy applies: everything except a declared side is a file that will not be transcribed.
     */
    public function testTheUploadFormFollowsTheChosenRecordingType(): void
    {
        $body = self::functionBody(self::dialog(), 'function apply(');

        self::assertNotSame('', $body, 'the upload form toggle must exist');
        self::assertTrue(
            str_contains($body, "selected() !== 'MIXED'"),
            'only a declared side may show the transcription settings',
        );
        self::assertTrue(
            str_contains($body, 'options.hidden = !transcribes;'),
            'the engine and AI-audio fields follow the chosen type',
        );
        self::assertTrue(
            str_contains($body, "data-a2t-store-label"),
            'the button must stop promising a transcription it will not perform',
        );
    }

    /**
     * The Update Audio dialog hides the transcription settings for a mixed recording, and disables them.
     *
     * Hiding alone would leave a select contributing a value nobody chose to the submission. The
     * endpoint ignores it either way — the policy decides from the recording type — but a form that
     * posts a setting the operator was never shown is its own small misreport of what was asked for.
     */
    public function testTheUpdateDialogWithholdsTranscriptionSettingsFromAMixedRecording(): void
    {
        $body = self::functionBody(self::dialog(), 'function openUpdate(');

        self::assertNotSame('', $body, 'the update dialog must still exist');
        self::assertTrue(
            str_contains($body, "target.recordingType !== 'MIXED'"),
            'the dialog must read the stored recording type, not its label',
        );
        self::assertTrue(
            str_contains($body, 'settings.hidden = !transcribes;'),
            'the engine and the paid opt-in follow the recording type',
        );
        self::assertTrue(
            str_contains($body, 'inputs[i].disabled = !transcribes;'),
            'a hidden field must not still post a value',
        );
        self::assertTrue(
            str_contains($body, 'audioOnly.hidden = transcribes;'),
            'a mixed recording must be told why there is nothing to configure',
        );
    }

    /** A form with nothing chosen reads as Mix / Common, which is what the server makes of it too. */
    public function testNoChoiceReadsAsAMixedRecording(): void
    {
        $body = self::functionBody(self::dialog(), 'function selected(');

        self::assertTrue(
            str_contains($body, "return checked ? checked.value : 'MIXED';"),
            'an unchecked radio group must not read as a transcribable recording',
        );
    }

    /**
     * The body of one function, from its opening brace to the matching close.
     *
     * Brace-counted rather than regex-matched: these functions contain both braces and quoted selector
     * strings, and a lazy match would stop at the first `}` inside one.
     */
    private static function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);

        if ($start === false) {
            return '';
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
