<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * A single turn of conversation passed to the provider — provider-neutral, so the chat domain does not
 * leak its own entities into the AI layer. Role is `user` or `assistant`.
 */
final readonly class ChatMessage
{
    private function __construct(
        public string $role,
        public string $text,
    ) {}

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    public static function assistant(string $text): self
    {
        return new self('assistant', $text);
    }
}
