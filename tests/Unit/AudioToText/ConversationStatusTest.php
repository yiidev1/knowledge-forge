<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Domain\JobStatus;
use PHPUnit\Framework\TestCase;

/**
 * The rule that turns several child states into the one state a store's history shows.
 *
 * Worth its own test because a separate upload is two jobs the queue may run minutes apart with
 * unrelated work in between, and the interesting cases are exactly the mixed ones — where a template
 * full of conditionals would quietly get it wrong.
 */
final class ConversationStatusTest extends TestCase
{
    // ------------------------------------------------------------- one child (a common upload)

    public function testASingleQueuedChildIsQueued(): void
    {
        self::assertSame(
            ConversationStatus::QUEUED,
            ConversationStatus::fromChildren([JobStatus::QUEUED]),
        );
    }

    public function testASingleCompletedChildIsCompleted(): void
    {
        self::assertSame(
            ConversationStatus::COMPLETED,
            ConversationStatus::fromChildren([JobStatus::COMPLETED]),
        );
    }

    public function testASingleFailedChildIsFailed(): void
    {
        self::assertSame(
            ConversationStatus::FAILED,
            ConversationStatus::fromChildren([JobStatus::FAILED]),
        );
    }

    // ------------------------------------------------------------- two children (a separate pair)

    public function testBothQueuedIsQueued(): void
    {
        self::assertSame(
            ConversationStatus::QUEUED,
            ConversationStatus::fromChildren([JobStatus::QUEUED, JobStatus::QUEUED]),
        );
    }

    public function testOneProcessingIsProcessing(): void
    {
        self::assertSame(
            ConversationStatus::PROCESSING,
            ConversationStatus::fromChildren([JobStatus::PROCESSING, JobStatus::QUEUED]),
        );
    }

    /**
     * The first recording is done and the second has not been picked up yet.
     *
     * PROCESSING rather than QUEUED: work on this upload has demonstrably started, and telling an
     * administrator it is still queued when half of it is transcribed would be a lie about progress.
     */
    public function testOneFinishedAndOneStillWaitingIsProcessing(): void
    {
        self::assertSame(
            ConversationStatus::PROCESSING,
            ConversationStatus::fromChildren([JobStatus::COMPLETED, JobStatus::QUEUED]),
        );
    }

    public function testOneFailedAndOneStillWaitingIsProcessing(): void
    {
        self::assertSame(
            ConversationStatus::PROCESSING,
            ConversationStatus::fromChildren([JobStatus::FAILED, JobStatus::QUEUED]),
        );
    }

    public function testBothCompletedIsCompleted(): void
    {
        self::assertSame(
            ConversationStatus::COMPLETED,
            ConversationStatus::fromChildren([JobStatus::COMPLETED, JobStatus::COMPLETED]),
        );
    }

    public function testBothFailedIsFailed(): void
    {
        self::assertSame(
            ConversationStatus::FAILED,
            ConversationStatus::fromChildren([JobStatus::FAILED, JobStatus::FAILED]),
        );
    }

    /**
     * The case the whole enum exists for.
     *
     * A failed Agent recording must not make a perfectly good Customer transcript look lost, and a
     * blanket FAILED would do exactly that.
     */
    public function testOneCompletedAndOneFailedIsPartiallyCompleted(): void
    {
        self::assertSame(
            ConversationStatus::PARTIALLY_COMPLETED,
            ConversationStatus::fromChildren([JobStatus::COMPLETED, JobStatus::FAILED]),
        );

        // Order is not part of the rule: which of the two failed changes nothing.
        self::assertSame(
            ConversationStatus::PARTIALLY_COMPLETED,
            ConversationStatus::fromChildren([JobStatus::FAILED, JobStatus::COMPLETED]),
        );
    }

    // ------------------------------------------------------------- edges

    /**
     * Not a state a conversation can legitimately reach — parent and children are written in one
     * transaction — but a purge racing a page read must produce a status, not a crash.
     */
    public function testNoChildrenReportsQueuedRatherThanFailing(): void
    {
        self::assertSame(ConversationStatus::QUEUED, ConversationStatus::fromChildren([]));
    }

    public function testOnlyTerminalStatesAreTerminal(): void
    {
        self::assertFalse(ConversationStatus::QUEUED->isTerminal());
        self::assertFalse(ConversationStatus::PROCESSING->isTerminal());
        self::assertTrue(ConversationStatus::COMPLETED->isTerminal());
        self::assertTrue(ConversationStatus::PARTIALLY_COMPLETED->isTerminal());
        self::assertTrue(ConversationStatus::FAILED->isTerminal());
    }

    /**
     * Every state must map to a badge class that exists in the stylesheet.
     *
     * The one that would have slipped through is PARTIALLY_COMPLETED: `strtolower($case->value)` gives
     * `partially_completed`, which matches no rule and paints an unstyled badge nobody notices until
     * the single state that hits it finally appears.
     */
    public function testEveryStateHasAStyledBadge(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.css');

        foreach (ConversationStatus::cases() as $case) {
            $selector = '.a2t-badge--' . $case->badgeModifier();

            self::assertStringContainsString(
                $selector . ' {',
                $css,
                $selector . ' is used by the store history but defined nowhere.',
            );
        }
    }
}
