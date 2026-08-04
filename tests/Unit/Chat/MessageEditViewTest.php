<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Web\MessageEditView;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertTrue;

/**
 * The server-computed edit affordances the templates rely on: only the latest question is editable and
 * only within the window; a latest question with no active answer is offered a retry and blocks the
 * composer; and nothing is offered when the base is not chat-ready.
 */
final class MessageEditViewTest extends Unit
{
    private const CONVERSATION = 100;
    private const WINDOW = 20;

    private InMemoryMessageRepository $messages;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->messages = new InMemoryMessageRepository();
        $this->now = new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'));
    }

    public function testLatestQuestionWithinWindowIsEditableAndNotRetryable(): void
    {
        $userId = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q'), $this->now);
        $this->messages->insertActiveAnswer($this->answer($userId), $this->now->modify('+1 second'));

        $view = $this->compute(true, $this->now->modify('+5 minutes'));

        assertTrue($view->isEditable($userId));
        assertFalse($view->isRetry($userId));
        assertFalse($view->hasBlockedComposer());
    }

    public function testQuestionPastWindowIsNotEditable(): void
    {
        $userId = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q'), $this->now);
        $this->messages->insertActiveAnswer($this->answer($userId), $this->now->modify('+1 second'));

        $view = $this->compute(true, $this->now->modify('+21 minutes'));

        assertFalse($view->isEditable($userId));
        assertNull($view->editableMessageId);
    }

    public function testOnlyTheLatestQuestionIsEditable(): void
    {
        $firstUser = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q1'), $this->now);
        $this->messages->insertActiveAnswer($this->answer($firstUser), $this->now->modify('+1 second'));
        $secondUser = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q2'), $this->now->modify('+2 seconds'));
        $this->messages->insertActiveAnswer($this->answer($secondUser), $this->now->modify('+3 seconds'));

        $view = $this->compute(true, $this->now->modify('+5 minutes'));

        assertFalse($view->isEditable($firstUser));
        assertTrue($view->isEditable($secondUser));
    }

    public function testUnansweredLatestQuestionOffersRetryAndBlocksComposer(): void
    {
        $userId = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q'), $this->now);
        // No active answer (or a superseded one) → unanswered.

        $view = $this->compute(true, $this->now->modify('+5 minutes'));

        assertTrue($view->isRetry($userId));
        assertTrue($view->hasBlockedComposer());
        // A within-window question can be both editable and retryable.
        assertTrue($view->isEditable($userId));
    }

    public function testNothingIsOfferedWhenNotChatReady(): void
    {
        $userId = $this->messages->add(NewMessage::user(self::CONVERSATION, 'Q'), $this->now);

        $view = $this->compute(false, $this->now->modify('+1 minute'));

        assertNull($view->editableMessageId);
        assertNull($view->retryMessageId);
        assertFalse($view->isEditable($userId));
        assertFalse($view->hasBlockedComposer());
    }

    public function testEmptyThreadOffersNothing(): void
    {
        $view = $this->compute(true, $this->now);

        assertNull($view->editableMessageId);
        assertNull($view->retryMessageId);
    }

    private function compute(bool $chatReady, DateTimeImmutable $now): MessageEditView
    {
        return MessageEditView::compute(
            $this->messages,
            self::CONVERSATION,
            $this->messages->findRecentByConversation(self::CONVERSATION, 40),
            $chatReady,
            $now,
            self::WINDOW,
        );
    }

    private function answer(int $replyToMessageId): NewMessage
    {
        return new NewMessage(
            conversationId: self::CONVERSATION,
            role: MessageRole::Assistant,
            content: 'A',
            replyToMessageId: $replyToMessageId,
        );
    }
}
