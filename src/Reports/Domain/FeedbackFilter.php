<?php

declare(strict_types=1);

namespace App\Reports\Domain;

/**
 * Whether the rating carries a written note.
 *
 * A comment can only exist on a low score (1–3) — enforced both in `ScoreChatAnswerService` and by a database
 * CHECK — so "has comment" is always a subset of the low-rating bucket.
 */
enum FeedbackFilter: string
{
    case All = 'all';
    case WithComment = 'with_comment';
    case WithoutComment = 'without_comment';

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All feedback',
            self::WithComment => 'Has comment',
            self::WithoutComment => 'No comment',
        };
    }
}
