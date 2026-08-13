<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * The reader may not see the requested source through this answer.
 *
 * Every rejection reason collapses to one 404 for the same reason as {@see MessageNotScorable}: the reply
 * must not distinguish "no such document" from "a real document this answer did not cite" from "a document
 * hidden by the Store Profile policy". Any of those distinctions would turn the endpoint into an oracle for
 * enumerating document ids.
 *
 * The messages below are for logs, never for the response body.
 */
final class SourceNotVisible
{
    public static function notAVisibleAnswer(int $messageId): NotFoundException
    {
        return new NotFoundException(
            'source_not_found',
            sprintf('Message #%d is not a visible assistant answer.', $messageId),
        );
    }

    public static function notCitedByAnswer(int $documentId, int $messageId): NotFoundException
    {
        return new NotFoundException(
            'source_not_found',
            sprintf('Document #%d is not a visible source of message #%d.', $documentId, $messageId),
        );
    }
}
