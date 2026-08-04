<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * A message does not exist within the given conversation. Scoped lookups raise this so a message id cannot
 * be reached under a conversation it does not belong to — a forged id is indistinguishable from a missing
 * one, and both become a 404.
 */
final class MessageNotFound
{
    public static function inConversation(int $messageId, int $conversationId): NotFoundException
    {
        return new NotFoundException(
            'message_not_found',
            sprintf('Message #%d was not found in conversation #%d.', $messageId, $conversationId),
        );
    }
}
