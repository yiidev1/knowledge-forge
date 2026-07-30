<?php

declare(strict_types=1);

namespace App\Chat\Domain;

/**
 * Who authored a message: the administrator asking, or the assistant answering.
 */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    public function isUser(): bool
    {
        return $this === self::User;
    }
}
