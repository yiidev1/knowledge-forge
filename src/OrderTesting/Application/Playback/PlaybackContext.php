<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Playback;

/**
 * Who is listening, and to what — every field decided by the server.
 *
 * Assembled by the action from the authenticated session and from the recording its middleware has
 * already admitted. It exists so the tracker cannot be handed an identity by a request: there is no
 * constructor path from posted data to any of these fields.
 */
final readonly class PlaybackContext
{
    public function __construct(
        public int $agentAdminId,
        public string $agentDisplayName,
        public int $storeSourceId,
        public ?int $sourceOrderId,
        public string $jobPublicId,
        public ?string $recordingLabel,
    ) {}
}
