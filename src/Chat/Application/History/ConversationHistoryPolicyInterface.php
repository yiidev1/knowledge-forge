<?php

declare(strict_types=1);

namespace App\Chat\Application\History;

use App\Ai\Contract\Dto\ChatMessage;
use App\Chat\Domain\Message;

/**
 * Decides how much prior conversation to send to the model. Behind an interface so the trimming policy
 * can change (or be tuned per deployment) without touching the ask service.
 */
interface ConversationHistoryPolicyInterface
{
    /**
     * @param list<Message> $history Prior messages, oldest first (excluding the current question).
     *
     * @return list<ChatMessage> Provider-neutral turns, oldest first, bounded by the policy.
     */
    public function select(array $history): array;
}
