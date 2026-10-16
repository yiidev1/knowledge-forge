<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/** Everything needed to open one attempt. Every field is server-decided except the token and the gap. */
final readonly class NewPlaybackSession
{
    public function __construct(
        public string $publicId,
        public int $agentAdminId,
        public string $agentDisplayName,
        public int $storeSourceId,
        public ?int $sourceOrderId,
        public string $jobPublicId,
        public ?string $recordingLabel,
        public int $gapMs,
        public int $messageTotal,
    ) {}
}
