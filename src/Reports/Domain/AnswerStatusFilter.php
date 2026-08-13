<?php

declare(strict_types=1);

namespace App\Reports\Domain;

/**
 * The grounding outcome of the current answer.
 *
 * Read from `messages.is_grounded`, which is the binary the chat pipeline actually guarantees: an answer is
 * either supported by a resolved citation or it was replaced by the fallback sentence. `answer_source` is
 * deliberately not used — answers written before that column existed carry NULL, and would be
 * misclassified.
 */
enum AnswerStatusFilter: string
{
    case All = 'all';
    case Grounded = 'grounded';
    case Fallback = 'fallback';
    case Unanswered = 'unanswered';

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All answers',
            self::Grounded => 'Grounded',
            self::Fallback => 'Fallback',
            self::Unanswered => 'Unanswered',
        };
    }
}
