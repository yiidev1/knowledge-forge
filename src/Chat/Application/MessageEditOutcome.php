<?php

declare(strict_types=1);

namespace App\Chat\Application;

/**
 * The result of editing a question (or retrying its answer).
 *
 * An edit always persists — the question text, its revision, and the superseding of the old answer commit
 * before the provider is ever called. What can still vary is whether the fresh answer was generated: a
 * provider failure leaves the question saved with no active answer, which the UI surfaces as a recoverable
 * "Retry" state rather than an error. This value object distinguishes those two outcomes without throwing,
 * so the web action can flash the right message.
 */
final readonly class MessageEditOutcome
{
    private function __construct(
        public bool $answerRegenerated,
    ) {}

    public static function regenerated(): self
    {
        return new self(true);
    }

    public static function regenerationFailed(): self
    {
        return new self(false);
    }
}
