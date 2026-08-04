<?php

declare(strict_types=1);

namespace App\Chat\Web;

/**
 * UI window size for the persistent chat thread (not the OpenAI history budget).
 */
final class ChatThreadParams
{
    public const RECENT_MESSAGE_LIMIT = 40;
}
