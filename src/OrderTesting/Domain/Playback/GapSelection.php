<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/**
 * A dropdown change that belongs to no attempt.
 *
 * It carries the same identity a session does — agent, store, order, recording — because the question
 * "what did this agent try on this recording" has to be answerable without a playback existing. What
 * it deliberately has no room for is a session id or a status: it is not an attempt, and the schema
 * is what stops it being counted as one.
 */
final readonly class GapSelection
{
    public function __construct(
        public int $agentAdminId,
        public string $agentDisplayName,
        public int $storeSourceId,
        public ?int $sourceOrderId,
        public string $jobPublicId,
        public ?string $recordingLabel,
        public int $gapMs,
    ) {}
}
