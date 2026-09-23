<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use Codeception\Test\Unit;
use DateTimeImmutable;

/**
 * What makes several uploads one row, and what that row then says about itself.
 *
 * Pure objects, no database: the repository's own suite covers the SQL. What is pinned here is the
 * reasoning the page depends on — the key two uploads share, the label a legacy half keeps, and the
 * rule that turns several job statuses into the one word a row shows.
 *
 * Three of these tests exist because getting them wrong would be quiet rather than loud. A collapsed
 * order-less key merges unrelated calls into one row; a dropped duplicate hides a recording that was
 * uploaded and paid for; and a status taken from whichever recording happens to be newest would report
 * a half-finished order as finished.
 */
final class StoreOrderGroupTest extends Unit
{
    // ------------------------------------------------------------------------------- the key

    public function testAnOrderIsTheKeyHoweverManyUploadsItHas(): void
    {
        $key = GroupKey::forOrder('16513791');

        self::assertSame('order:16513791', $key->value);
        self::assertSame('16513791', $key->orderId());
        self::assertTrue($key->equals(GroupKey::forOrder('16513791')));
    }

    /**
     * The case most rows in this database are in.
     *
     * Fifty of the fifty-two conversations that existed when this was written named no order. They must
     * never share a key: two unrelated recordings uploaded on different days are two rows, not one row
     * called "no order".
     */
    public function testAnUploadWithNoOrderIsKeyedByItself(): void
    {
        $first = GroupKey::forConversation(str_repeat('a', 32));
        $second = GroupKey::forConversation(str_repeat('b', 32));

        self::assertNotSame($first->value, $second->value);
        self::assertFalse($first->equals($second));
        self::assertNull($first->orderId(), 'It names a conversation, not an order.');
    }

    /**
     * @dataProvider malformedKeys
     */
    public function testAKeyThisApplicationCouldNotHaveIssuedIsRefused(string $raw): void
    {
        self::assertNull(GroupKey::fromInput($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function malformedKeys(): iterable
    {
        yield 'no namespace' => ['16513791'];
        yield 'unknown namespace' => ['store:16513791'];
        yield 'order with letters' => ['order:16513791x'];
        yield 'order with a sign' => ['order:-16513791'];
        yield 'empty order' => ['order:'];
        yield 'short conversation id' => ['conversation:' . str_repeat('a', 31)];
        yield 'uppercase conversation id' => ['conversation:' . str_repeat('A', 32)];
        yield 'trailing newline' => ["order:16513791\n"];
        yield 'sql-shaped' => ["order:1' OR '1'='1"];
    }

    /**
     * @dataProvider wellFormedKeys
     */
    public function testAWellFormedKeySurvivesTheRoundTrip(string $raw): void
    {
        $key = GroupKey::fromInput($raw);

        self::assertNotNull($key);
        self::assertSame($raw, $key->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function wellFormedKeys(): iterable
    {
        yield 'an order' => ['order:16513791'];
        yield 'a single digit' => ['order:1'];
        yield 'a conversation' => ['conversation:' . str_repeat('f', 32)];
    }

    /** SQL and PHP must describe the same row the same way, so both read the format from one place. */
    public function testTheSqlExpressionCarriesBothNamespaces(): void
    {
        $sql = GroupKey::sqlExpression('c');

        self::assertStringContainsString("CONCAT('order:', c.order_id)", $sql);
        self::assertStringContainsString("CONCAT('conversation:', c.public_id)", $sql);
        self::assertStringContainsString('c.order_id IS NOT NULL', $sql);
    }

    // ------------------------------------------------------------------------------ the labels

    public function testARecordingIsCalledWhatItsTypeCallsIt(): void
    {
        self::assertSame('Caller', $this->slot(RecordingType::Caller)->label());
        self::assertSame('Callee', $this->slot(RecordingType::Callee)->label());
        self::assertSame('Common / Mixed', $this->slot(RecordingType::Mixed)->label());
    }

    /**
     * A legacy half keeps the name the administrator gave it.
     *
     * Customer is not Caller and Agent is not Callee: nothing in this application has ever recorded
     * which side placed the call, so relabelling a legacy pair into the new vocabulary would be
     * inventing a fact to fill a column.
     */
    public function testALegacyHalfIsNotRelabelledIntoTheNewVocabulary(): void
    {
        $customer = $this->slot(null, SourceRole::Customer);
        $agent = $this->slot(null, SourceRole::Agent);

        self::assertSame(SourceRole::Customer->label(), $customer->label());
        self::assertSame(SourceRole::Agent->label(), $agent->label());
        self::assertNotSame('Caller', $customer->label());
        self::assertNotSame('Callee', $agent->label());
    }

    // ------------------------------------------------------------------------- what a row holds

    public function testEveryRecordingOfAnOrderIsCountedIncludingTheOlderOnes(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed),
            caller: $this->slot(RecordingType::Caller, older: [$this->slot(RecordingType::Caller)]),
            callee: $this->slot(RecordingType::Callee),
        );

        self::assertCount(3, $group->primaries(), 'Three cells, one per recording type.');
        self::assertSame(4, $group->recordingCount(), 'The second caller upload is folded, not dropped.');
        self::assertSame(1, $group->caller?->olderCount());
    }

    /** A pair uploaded before recording types existed fills the Mix / Common cell, and only that one. */
    public function testALegacyPairIsOneRowWithNoCallerOrCallee(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forConversation(str_repeat('c', 32)),
            null,
            new DateTimeImmutable('2026-09-23 10:00:00'),
            legacySeparate: [
                $this->slot(null, SourceRole::Customer),
                $this->slot(null, SourceRole::Agent),
            ],
        );

        self::assertTrue($group->isLegacySeparate());
        self::assertNull($group->caller);
        self::assertNull($group->callee);
        self::assertSame(2, $group->recordingCount());
    }

    // ------------------------------------------------------------------------------ the status

    /**
     * One word for the whole row, from the rule this application already uses.
     *
     * Delegated to {@see ConversationStatus::fromChildren()} rather than re-derived, so a row and the
     * conversion page behind it cannot disagree about whether an order is finished.
     *
     * @dataProvider statusCombinations
     *
     * @param list<JobStatus> $statuses
     */
    public function testARowsStatusIsAggregatedFromEveryRecording(
        array $statuses,
        ConversationStatus $expected,
    ): void {
        $slots = [];

        foreach ($statuses as $status) {
            $slots[] = $this->slot(RecordingType::Mixed, status: $status);
        }

        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $slots[0],
            caller: $slots[1] ?? null,
            callee: $slots[2] ?? null,
        );

        self::assertSame($expected, $group->aggregateStatus());
        self::assertSame(
            ConversationStatus::fromChildren($statuses),
            $group->aggregateStatus(),
            'The row must not have a second opinion about what these statuses mean.',
        );
    }

    /**
     * @return iterable<string, array{list<JobStatus>, ConversationStatus}>
     */
    public function statusCombinations(): iterable
    {
        yield 'all queued' => [[JobStatus::QUEUED], ConversationStatus::QUEUED];
        yield 'all done' => [
            [JobStatus::COMPLETED, JobStatus::COMPLETED, JobStatus::COMPLETED],
            ConversationStatus::COMPLETED,
        ];
        yield 'one still running' => [
            [JobStatus::COMPLETED, JobStatus::PROCESSING],
            ConversationStatus::PROCESSING,
        ];
        // The combination the row exists to make visible: two of three recordings are ready, so an
        // administrator reading "Completed" would go looking for a transcript that is not there.
        yield 'one failed beside two finished' => [
            [JobStatus::COMPLETED, JobStatus::COMPLETED, JobStatus::FAILED],
            ConversationStatus::PARTIALLY_COMPLETED,
        ];
        yield 'everything failed' => [
            [JobStatus::FAILED, JobStatus::FAILED],
            ConversationStatus::FAILED,
        ];
    }

    /** An order with nothing transcribed offers no way in to a transcript. */
    public function testAnOrderWithNothingTranscribedOffersNoTranscript(): void
    {
        $group = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed, status: JobStatus::QUEUED, hasTranscript: false),
        );

        self::assertFalse($group->hasAnyTranscript());

        $completed = new StoreOrderGroup(
            GroupKey::forOrder('16513791'),
            '16513791',
            new DateTimeImmutable('2026-09-23 10:00:00'),
            mixed: $this->slot(RecordingType::Mixed),
        );

        self::assertTrue($completed->hasAnyTranscript());
    }

    /**
     * @param list<StoreRecordingSlot> $older
     */
    private function slot(
        ?RecordingType $type,
        SourceRole $role = SourceRole::Common,
        JobStatus $status = JobStatus::COMPLETED,
        bool $hasTranscript = true,
        array $older = [],
    ): StoreRecordingSlot {
        return new StoreRecordingSlot(
            conversationPublicId: str_repeat('a', 32),
            jobPublicId: str_repeat('b', 32),
            recordingType: $type,
            sourceRole: $role,
            status: $status,
            provider: TranscriptionProvider::Whisper,
            durationSeconds: 206.3,
            uploadedAt: new DateTimeImmutable('2026-09-23 10:00:00'),
            originalFilename: 'call.wav',
            hasOriginalAudio: true,
            hasTranscript: $hasTranscript,
            hasSegments: $hasTranscript,
            rolesConfirmed: false,
            older: $older,
        );
    }
}
