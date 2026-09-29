<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\Shared\Audio\RecordingTypeLabels;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * The one table of words a recording type is shown by, and the line it must not cross.
 *
 * Two separate claims are made here and they pull in opposite directions, which is why they are in one
 * file. The client's operators read Customer and Agent, so that is what the interface says. The call
 * only ever recorded who dialled, so that is what the database says. Both have to stay true at once, and
 * the way that fails is always the same: somebody reads the label, believes it, and writes a line of
 * code that treats a caller recording as a customer recording.
 */
final class RecordingTypeLabelsTest extends TestCase
{
    public function testTheThreeStoredValuesAreShownInTheClientsVocabulary(): void
    {
        self::assertSame('Mix / Common', RecordingTypeLabels::forStorageValue('MIXED'));
        self::assertSame('Customer', RecordingTypeLabels::forStorageValue('CALLER'));
        self::assertSame('Agent', RecordingTypeLabels::forStorageValue('CALLEE'));
    }

    /**
     * The map is keyed by the stored value, and those are the values that are stored.
     *
     * This is what makes the seam safe: Order58 hands over a plain `'CALLER'` because it may not name
     * the enum, and nothing checks that string against anything. If the two ever disagreed the card
     * would quietly show nothing where a count belongs.
     */
    public function testEveryStoredValueHasALabelAndNothingElseDoes(): void
    {
        $cases = [];

        foreach (RecordingType::cases() as $case) {
            $cases[] = $case->value;
            self::assertNotNull(
                RecordingTypeLabels::forStorageValue($case->value),
                $case->value . ' is a value the column can hold and must be displayable.',
            );
        }

        self::assertSame($cases, array_keys(RecordingTypeLabels::all()));
    }

    /**
     * The short form exists for one reason: a strip that gives each label a quarter of a card.
     *
     * Only MIXED differs. "Mix / Common" is wider than that column, and the label is
     * `white-space: nowrap`, so it did not wrap or clip — it drew over the cell beside it. The other
     * two are single words and are the same either way, which is asserted so that a future "tidy-up"
     * that shortens them further has to be a deliberate decision rather than a silent one.
     */
    public function testTheShortFormDiffersOnlyWhereTheFullOneDoesNotFit(): void
    {
        self::assertSame('Mix', RecordingTypeLabels::shortForStorageValue('MIXED'));
        self::assertNotSame(
            RecordingTypeLabels::forStorageValue('MIXED'),
            RecordingTypeLabels::shortForStorageValue('MIXED'),
        );

        foreach (['CALLER', 'CALLEE'] as $stored) {
            self::assertSame(
                RecordingTypeLabels::forStorageValue($stored),
                RecordingTypeLabels::shortForStorageValue($stored),
                $stored . ' is one word already; there is nothing to shorten.',
            );
        }
    }

    /** Both forms cover exactly the values the column can hold, and refuse anything else. */
    public function testEveryStoredValueHasAShortLabelToo(): void
    {
        foreach (RecordingType::cases() as $case) {
            self::assertNotNull(RecordingTypeLabels::shortForStorageValue($case->value));
        }

        self::assertNull(RecordingTypeLabels::shortForStorageValue('CALLER_2'));
        self::assertNull(RecordingTypeLabels::shortForStorageValue(null));
    }

    /** An unknown value is refused rather than echoed onto a page. */
    public function testAnUnknownValueHasNoLabel(): void
    {
        self::assertNull(RecordingTypeLabels::forStorageValue('CALLER_2'));
        self::assertNull(RecordingTypeLabels::forStorageValue('customer'));
        self::assertNull(RecordingTypeLabels::forStorageValue(''));
        self::assertNull(RecordingTypeLabels::forStorageValue(null));
    }

    /** The enum shows what the map says, so there is one table of words rather than two. */
    public function testTheEnumDelegatesRatherThanKeepingItsOwnCopy(): void
    {
        foreach (RecordingType::cases() as $case) {
            self::assertSame(RecordingTypeLabels::forStorageValue($case->value), $case->label());
        }
    }

    /**
     * Renaming the labels never moves the stored values.
     *
     * The assertion that matters most in this file. These three strings are in the database, in the
     * provider's channel mapping and in the `replaces` derivation that decides which slot an upload
     * lands in — a display change that reached any of them would refile recordings.
     */
    public function testTheStoredValuesAreUntouchedByWhateverTheyAreCalled(): void
    {
        self::assertSame('MIXED', RecordingType::Mixed->value);
        self::assertSame('CALLER', RecordingType::Caller->value);
        self::assertSame('CALLEE', RecordingType::Callee->value);
    }

    /**
     * The display words collide with {@see SourceRole}'s. The concepts do not, and must not.
     *
     * A legacy SEPARATE upload's customer half has always read "Customer" — it is the half recorded on
     * the customer's own microphone. A CALLER recording now reads "Customer" as well, meaning the side
     * that dialled. Accepted deliberately: the operators asked for their own vocabulary, and separate
     * pairs are historical.
     *
     * So the guard moves from the label to the type. Nothing may convert between the two enums, and the
     * values behind those identical words stay different — which is what stops the next reader
     * "simplifying" one into the other.
     */
    public function testTheLabelsCollideWithSourceRoleWhileTheConceptsStaySeparate(): void
    {
        self::assertSame(SourceRole::Customer->label(), RecordingType::Caller->label());
        self::assertSame(SourceRole::Agent->label(), RecordingType::Callee->label());

        self::assertNotSame(SourceRole::Customer->value, RecordingType::Caller->value);
        self::assertNotSame(SourceRole::Agent->value, RecordingType::Callee->value);

        // COMMON is the source role a caller recording actually carries — its speakers still have to be
        // discovered. It is not CUSTOMER, however the type is spelled on screen.
        self::assertSame('COMMON', SourceRole::Common->value);
    }

    /**
     * A mixed upload made today and one made before the column existed read identically.
     *
     * The second has no recording type at all and falls back to its conversation mode, so the two
     * labels have to agree or the same thing appears twice in one table under two names.
     */
    public function testALegacyMixedUploadReadsExactlyLikeANewOne(): void
    {
        self::assertSame(RecordingType::Mixed->label(), ConversationMode::Common->label());
    }
}
