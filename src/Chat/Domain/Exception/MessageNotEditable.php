<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * The targeted message is not an editable question — an assistant answer, which the UI never offers to
 * edit. Treated as a 404 (not a distinct error shown to the user): the only way to reach it is a crafted
 * request, and revealing "this exists but you may not edit it" would leak more than "not found".
 */
final class MessageNotEditable
{
    public static function notAQuestion(int $messageId): NotFoundException
    {
        return new NotFoundException(
            'message_not_editable',
            sprintf('Message #%d is not an editable question.', $messageId),
        );
    }
}
