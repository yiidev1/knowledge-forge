<?php

declare(strict_types=1);

namespace App\Reports\Domain;

/**
 * Rating buckets for the detail table.
 *
 * "Unrated" is a precise state, not simply "no number": the question must have a current active answer, and
 * that answer must carry no numeric score. A **dismissed** answer is still unrated — declining to rate
 * stores no score, and must never read as a zero. A question with no active answer at all is not an unrated
 * *answer*; it is unanswered, and {@see AnswerStatusFilter::Unanswered} selects it.
 */
enum RatingFilter: string
{
    case All = 'all';
    case Rated = 'rated';
    case Unrated = 'unrated';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromRequest(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All ratings',
            self::Rated => 'Rated',
            self::Unrated => 'Unrated',
            self::Low => 'Low 1–3',
            self::Medium => 'Medium 4–7',
            self::High => 'High 8–10',
        };
    }

    /**
     * The inclusive score bounds this bucket selects, or null when the bucket is not a numeric range.
     *
     * @return array{0: int, 1: int}|null
     */
    public function scoreRange(): ?array
    {
        return match ($this) {
            self::Low => [1, 3],
            self::Medium => [4, 7],
            self::High => [8, 10],
            self::All, self::Rated, self::Unrated => null,
        };
    }
}
