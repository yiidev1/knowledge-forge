<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

/**
 * The targeted question is no longer the latest in its thread — a newer question was asked after the page
 * was rendered. Only the latest question is editable (editing an earlier one would invalidate every turn
 * after it), so this is a conflict (409 in semantics) surfaced as a flash, not a 404.
 */
final class NotLatestQuestion extends MessageEditException
{
    public function errorCode(): string
    {
        return 'not_latest_question';
    }

    public static function create(): self
    {
        return new self('This question can no longer be edited because a newer question was asked. Reload the chat to continue.');
    }
}
