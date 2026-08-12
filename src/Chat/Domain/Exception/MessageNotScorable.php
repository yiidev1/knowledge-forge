<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * The targeted message cannot carry a score — it is a question rather than an answer, or an answer that an
 * edit has superseded. The UI never offers a scoring control for either, so the only way to reach this is a
 * crafted request.
 *
 * Treated as a 404 for the same reason as {@see MessageNotEditable}: replying "this exists but is not
 * scorable" would confirm the id exists, which "not found" does not.
 */
final class MessageNotScorable
{
    public static function notAnAnswer(int $messageId): NotFoundException
    {
        return new NotFoundException(
            'message_not_scorable',
            sprintf('Message #%d is not an assistant answer.', $messageId),
        );
    }

    public static function superseded(int $messageId): NotFoundException
    {
        return new NotFoundException(
            'message_not_scorable',
            sprintf('Message #%d is a superseded answer.', $messageId),
        );
    }
}
