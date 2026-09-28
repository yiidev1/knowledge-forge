<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationChild;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\OrderId;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * What an upload records about itself: which card it came through, and which order it belongs to.
 *
 * Both are labels on the upload rather than inputs to the pipeline, so what these tests pin is the
 * part that can silently go wrong: the values that may be stored, the words they are shown as, and
 * what happens to a conversation that predates either column.
 */
final class RecordingTypeAndOrderIdTest extends TestCase
{
    // ------------------------------------------------------------------------ the type, as displayed

    /**
     * A mixed recording says exactly what a COMMON conversation has always said.
     *
     * This is what makes the whole change backward compatible: an upload from before the column and a
     * mixed upload made today are the same thing and are indistinguishable on the page.
     */
    public function testAMixedRecordingIsLabelledTheSameAsAPlainCommonUpload(): void
    {
        self::assertSame('Mix / Common', RecordingType::Mixed->label());
        self::assertSame(ConversationMode::Common->label(), RecordingType::Mixed->label());
    }

    /**
     * The two are shown to an administrator as Customer and Agent — and stored as CALLER and CALLEE.
     *
     * The display vocabulary is the client's: their operators think in Customer and Agent, and asking
     * them to read "Callee" is asking them to translate. The stored vocabulary is the call's: CALLER is
     * whoever dialled, which is a different fact and the only one this application actually knows.
     *
     * Both halves are asserted together on purpose. A change that renamed the cases or the column to
     * match the labels would pass one of these and fail the other, which is the whole point — the words
     * on the screen are allowed to move and the values underneath them are not.
     */
    public function testCallerAndCalleeAreShownInTheClientsVocabularyAndStoredInTheCalls(): void
    {
        self::assertSame('Customer', RecordingType::Caller->label());
        self::assertSame('Agent', RecordingType::Callee->label());

        self::assertSame('CALLER', RecordingType::Caller->value);
        self::assertSame('CALLEE', RecordingType::Callee->value);
    }

    /**
     * The display labels collide with {@see SourceRole}'s and the types do not. Accepted, deliberately.
     *
     * `SourceRole::Customer` has always read "Customer": it is the half of a legacy SEPARATE upload that
     * holds the customer's own microphone. `RecordingType::Caller` now reads "Customer" too, meaning the
     * person who dialled. On screen they are the same word; in the code they are two enums that no
     * branch maps onto each other, and a caller recording is still not a customer recording.
     *
     * Asserted rather than left implicit, because the collision is the sort of thing a later reader
     * would take for a bug and "fix" by making one of them defer to the other.
     */
    public function testTheDisplayLabelsCollideWithSourceRoleButTheTypesDoNot(): void
    {
        self::assertSame(SourceRole::Customer->label(), RecordingType::Caller->label());
        self::assertSame(SourceRole::Agent->label(), RecordingType::Callee->label());

        // Presentation only. Nothing converts between the two vocabularies, and the stored values of
        // the one say nothing about the other.
        self::assertNotSame(SourceRole::Customer->value, RecordingType::Caller->value);
        self::assertNotSame(SourceRole::Agent->value, RecordingType::Callee->value);
    }

    public function testTheThreeStoredValuesAreTheOnesTheColumnAllows(): void
    {
        self::assertSame('MIXED', RecordingType::Mixed->value);
        self::assertSame('CALLER', RecordingType::Caller->value);
        self::assertSame('CALLEE', RecordingType::Callee->value);
    }

    // ------------------------------------------------------------------------ the type, as accepted

    /**
     * @dataProvider refusedRecordingTypes
     */
    public function testOnlyTheThreeKnownTypesAreEverAccepted(?string $posted): void
    {
        self::assertNull(
            RecordingType::fromStorage($posted),
            'A value outside the allow-list must record nothing rather than becoming a label.',
        );
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function refusedRecordingTypes(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'lower case' => ['caller'],
            'mixed case' => ['Caller'],
            'arbitrary' => ['anything'],
            'a mode' => ['COMMON'],
            'a source role' => ['CUSTOMER'],
            'sql-ish' => ["CALLER'; DROP TABLE audio_conversations; --"],
        ];
    }

    public function testTheThreeKnownTypesRoundTripThroughStorage(): void
    {
        foreach (RecordingType::cases() as $type) {
            self::assertSame($type, RecordingType::fromStorage($type->value));
        }
    }

    // ------------------------------------------------------------------------ the conversation label

    public function testAConversationReportsTheCardItCameThrough(): void
    {
        self::assertSame('Customer', $this->conversation(RecordingType::Caller)->typeLabel());
        self::assertSame('Agent', $this->conversation(RecordingType::Callee)->typeLabel());
        self::assertSame('Mix / Common', $this->conversation(RecordingType::Mixed)->typeLabel());
    }

    /**
     * An upload from before the column: no type recorded, so it reads as its mode.
     *
     * And its mode reads exactly what a mixed recording reads, which is what keeps a legacy upload and
     * one made today indistinguishable in the same table — they are the same thing.
     */
    public function testAConversationWithNoRecordedTypeFallsBackToItsMode(): void
    {
        $conversation = $this->conversation(null);

        self::assertNull($conversation->recordingType);
        self::assertSame('Mix / Common', $conversation->typeLabel());
        self::assertSame(RecordingType::Mixed->label(), $conversation->typeLabel());
    }

    /** A Customer + Agent pair is described by its mode, and these three values do not apply to it. */
    public function testASeparateUploadKeepsItsOwnLabel(): void
    {
        $conversation = $this->conversation(null, mode: ConversationMode::Separate);

        self::assertSame('Separate Customer + Agent', $conversation->typeLabel());
    }

    // ------------------------------------------------------------------------ the order id

    public function testAnOrderIdIsOptional(): void
    {
        self::assertNull(OrderId::validate(''), 'A blank order id is a valid upload.');
        self::assertNull(OrderId::validate('   '), 'Whitespace is the same as blank.');
        self::assertNull(OrderId::fromInput(''));
        self::assertNull(OrderId::fromInput('   '), 'Blank is stored as NULL, never as an empty string.');
    }

    public function testTheKnownOrderIdFormatIsAccepted(): void
    {
        self::assertNull(OrderId::validate('16513791'));
        self::assertSame('16513791', OrderId::fromInput('16513791'));
        self::assertSame('16513791', OrderId::fromInput('  16513791  '), 'Surrounding space is trimmed.');
    }

    /** Generous on purpose: a longer order number must not be refused by an arbitrary small cap. */
    public function testALongOrderNumberIsStillAccepted(): void
    {
        self::assertNull(OrderId::validate(str_repeat('9', OrderId::MAX_DIGITS)));
        self::assertNotNull(OrderId::validate(str_repeat('9', OrderId::MAX_DIGITS + 1)));
    }

    /**
     * @dataProvider refusedOrderIds
     */
    public function testANonNumericOrderIdIsRefused(string $raw): void
    {
        self::assertNotNull(OrderId::validate($raw), $raw . ' must not be accepted as an order id.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedOrderIds(): array
    {
        return [
            'letters' => ['abc'],
            'mixed' => ['16513791abc'],
            'negative' => ['-16513791'],
            'decimal' => ['165137.91'],
            'spaced inside' => ['165 13791'],
            'hyphenated' => ['58-100234'],
            'markup' => ['<script>alert(1)</script>'],
            // `$`-anchored patterns also match before a trailing newline; this must not slip through.
            'newline smuggling' => ["16513791\n../../etc/passwd"],
        ];
    }

    /** Nothing is ever stored as 0: a missing order and order number zero must not look alike. */
    public function testAMissingOrderIsNeverStoredAsZero(): void
    {
        self::assertNull(OrderId::fromInput(''));
        self::assertNotSame('0', OrderId::fromInput(''));
    }

    // ------------------------------------------------------------------------------ helpers

    private function conversation(
        ?RecordingType $type,
        ?string $orderId = null,
        ConversationMode $mode = ConversationMode::Common,
    ): AudioConversation {
        return new AudioConversation(
            1,
            str_repeat('a', 32),
            77,
            $mode,
            5,
            'admin',
            new DateTimeImmutable('2026-09-22 10:00:00'),
            [
                new AudioConversationChild(
                    str_repeat('b', 32),
                    SourceRole::Common,
                    JobStatus::QUEUED,
                    null,
                    'recording.wav',
                    null,
                    null,
                ),
            ],
            false,
            $type,
            $orderId,
        );
    }
}
