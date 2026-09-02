<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationChild;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SourceRole;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The shape a conversation promises, and what it reports about itself.
 *
 * The invariants cannot drift in practice — the enqueue writes the parent and its children in one
 * transaction — so these tests exist to make that a fact the build checks rather than a comment
 * somebody has to keep believing.
 */
final class AudioConversationTest extends TestCase
{
    public function testACommonUploadIsOneCommonRecording(): void
    {
        $conversation = $this->conversation(ConversationMode::Common, [
            $this->child(SourceRole::Common, JobStatus::QUEUED),
        ]);

        self::assertTrue($conversation->hasValidShape());
        self::assertNotNull($conversation->singleChild());
        self::assertSame(SourceRole::Common, $conversation->singleChild()?->sourceRole);
    }

    public function testASeparateUploadIsOneCustomerAndOneAgentRecording(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::QUEUED),
            $this->child(SourceRole::Agent, JobStatus::QUEUED),
        ]);

        self::assertTrue($conversation->hasValidShape());
        self::assertNotNull($conversation->childFor(SourceRole::Customer));
        self::assertNotNull($conversation->childFor(SourceRole::Agent));
        self::assertNull($conversation->childFor(SourceRole::Common));
    }

    public function testASeparateUploadMissingARoleHasAnInvalidShape(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::QUEUED),
        ]);

        self::assertFalse($conversation->hasValidShape());
    }

    /** Two recordings of the same role is not a conversation, however many children there are. */
    public function testTwoRecordingsOfTheSameRoleHasAnInvalidShape(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::QUEUED),
            $this->child(SourceRole::Customer, JobStatus::QUEUED),
        ]);

        self::assertFalse($conversation->hasValidShape());
    }

    public function testACommonUploadWithTwoChildrenHasAnInvalidShape(): void
    {
        $conversation = $this->conversation(ConversationMode::Common, [
            $this->child(SourceRole::Common, JobStatus::QUEUED),
            $this->child(SourceRole::Common, JobStatus::QUEUED),
        ]);

        self::assertFalse($conversation->hasValidShape());
        self::assertNull($conversation->singleChild());
    }

    // ------------------------------------------------------------------------------ status

    public function testTheStatusIsDerivedFromTheChildren(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::COMPLETED),
            $this->child(SourceRole::Agent, JobStatus::FAILED),
        ]);

        self::assertSame(ConversationStatus::PARTIALLY_COMPLETED, $conversation->status());
    }

    // ---------------------------------------------------------------------------- duration

    /**
     * Summed, not maximised: the two files are two recordings the machine has to transcribe, and the
     * total is what the queue actually has to get through.
     */
    public function testDurationIsTheSumOfEveryRecording(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::COMPLETED, 61.5),
            $this->child(SourceRole::Agent, JobStatus::COMPLETED, 58.5),
        ]);

        self::assertSame(120.0, $conversation->totalDurationSeconds());
    }

    /**
     * Null rather than 0.0 when nothing has been measured yet — a queued upload has no duration, and
     * printing "0.0s" would state a measurement nobody took.
     */
    public function testDurationIsNullWhenNothingHasBeenMeasured(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::QUEUED),
            $this->child(SourceRole::Agent, JobStatus::QUEUED),
        ]);

        self::assertNull($conversation->totalDurationSeconds());
    }

    public function testDurationCountsTheRecordingsThatHaveBeenMeasured(): void
    {
        $conversation = $this->conversation(ConversationMode::Separate, [
            $this->child(SourceRole::Customer, JobStatus::COMPLETED, 42.0),
            $this->child(SourceRole::Agent, JobStatus::QUEUED),
        ]);

        self::assertSame(42.0, $conversation->totalDurationSeconds());
    }

    // ------------------------------------------------------------------------------ helpers

    /**
     * @param list<AudioConversationChild> $children
     */
    private function conversation(ConversationMode $mode, array $children): AudioConversation
    {
        return new AudioConversation(
            1,
            str_repeat('a', 32),
            77,
            $mode,
            5,
            'admin',
            new DateTimeImmutable('2026-09-02 10:00:00'),
            $children,
        );
    }

    private function child(SourceRole $role, JobStatus $status, ?float $duration = null): AudioConversationChild
    {
        return new AudioConversationChild(
            str_repeat('b', 32),
            $role,
            $status,
            null,
            strtolower($role->value) . '.wav',
            $duration,
            null,
        );
    }
}
