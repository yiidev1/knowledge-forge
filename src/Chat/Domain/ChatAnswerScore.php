<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * One participant's feedback on one assistant answer.
 *
 * The two nullable fields encode three UI states and are never both null (the table enforces it):
 * a `score` means rated, a `dismissedAt` with no score means the participant declined to rate, and no row at
 * all means unrated. A dismissal is explicitly not a zero — {@see self::score} stays null — so it can never
 * be averaged in as a bad rating.
 */
final readonly class ChatAnswerScore
{
    public function __construct(
        public int $messageId,
        public ?int $score,
        public ?DateTimeImmutable $dismissedAt,
    ) {}

    public function isRated(): bool
    {
        return $this->score !== null;
    }

    /**
     * Declined, and not since rated. A row that was dismissed and later scored reads as rated, because
     * saving a score clears the dismissal.
     */
    public function isDismissed(): bool
    {
        return $this->score === null && $this->dismissedAt !== null;
    }
}
