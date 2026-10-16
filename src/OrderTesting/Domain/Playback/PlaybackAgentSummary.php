<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/**
 * What one agent did to one recording, counted.
 *
 * The six figures are kept apart on purpose. They answer different questions and mixing any two of
 * them produces a number that is not true of anything: a dropdown change is not an attempt, an attempt
 * is not a play, and a play that failed before the first word is not a play either.
 */
final readonly class PlaybackAgentSummary
{
    public function __construct(
        public int $agentAdminId,
        public string $agentDisplayName,
        public string $jobPublicId,
        public ?string $recordingLabel,
        /** Attempts: Play was pressed and accepted. */
        public int $playRequests,
        /** Of those, the ones the engine actually began. This is the headline figure. */
        public int $playStarts,
        public int $completed,
        /** Started, then ended early. */
        public int $stopped,
        /** Failed before the first word was spoken. Never counted as a play. */
        public int $errorsBeforeStart,
        /** Began, then failed. Counted as a play, because it played. */
        public int $errorsAfterStart,
        /** Dropdown changes that produced no attempt at all. */
        public int $gapSelections,
        public ?string $lastStartedAt,
    ) {}
}
