<?php

declare(strict_types=1);

namespace App\Chat\Application\History;

use App\Ai\Contract\Dto\ChatMessage;
use App\Chat\Domain\Message;
use App\Chat\Domain\MessageRole;

use function array_reverse;
use function array_slice;
use function mb_strlen;

/**
 * Keeps the newest messages within two bounds: at most N messages, and at most C characters in total,
 * trimming oldest-first. We deliberately do NOT use the provider's `previous_response_id`: the database
 * stays the single source of truth and provider-independent, and with `store: false` there is nothing
 * retained on the provider to chain from anyway.
 */
final readonly class RecentMessagesHistoryPolicy implements ConversationHistoryPolicyInterface
{
    public function __construct(
        private int $messageLimit,
        private int $charLimit,
    ) {}

    public function select(array $history): array
    {
        // Newest first so trimming drops the oldest, then reverse back to chronological order.
        $newestFirst = array_reverse($history);
        if ($this->messageLimit > 0) {
            $newestFirst = array_slice($newestFirst, 0, $this->messageLimit);
        }

        $selected = [];
        $used = 0;
        foreach ($newestFirst as $message) {
            $used += mb_strlen($message->content);
            if ($this->charLimit > 0 && $used > $this->charLimit && $selected !== []) {
                break;
            }
            $selected[] = $this->toChatMessage($message);
        }

        return array_reverse($selected);
    }

    private function toChatMessage(Message $message): ChatMessage
    {
        return $message->role === MessageRole::User
            ? ChatMessage::user($message->content)
            : ChatMessage::assistant($message->content);
    }
}
