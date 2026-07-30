<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * A conversation does not exist within the given knowledge base. Scoped lookups raise this so a
 * conversation id cannot be opened under another base.
 */
final class ConversationNotFound
{
    public static function inKnowledgeBase(int $conversationId, int $knowledgeBaseId): NotFoundException
    {
        return new NotFoundException(
            'conversation_not_found',
            sprintf('Conversation #%d was not found in knowledge base #%d.', $conversationId, $knowledgeBaseId),
        );
    }
}
