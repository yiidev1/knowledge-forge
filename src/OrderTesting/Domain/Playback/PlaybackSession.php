<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/** One attempt by one agent to hear one recording read aloud. */
final readonly class PlaybackSession
{
    public function __construct(
        public int $id,
        /** The browser's idempotency token. Two requests carrying it are one attempt. */
        public string $publicId,
        /** Order58's own `admin_id` — the identity the session carries, never a local row id. */
        public int $agentAdminId,
        public string $agentDisplayName,
        public int $storeSourceId,
        /** Null where the recording names no mirrored order; see GroupKey's two namespaces. */
        public ?int $sourceOrderId,
        /** The recording actually played: one transcription job's public id. */
        public string $jobPublicId,
        public ?string $recordingLabel,
        public int $gapMs,
        public int $messageTotal,
        public int $messagePlayed,
        public PlaybackStatus $status,
        public string $requestedAt,
        /**
         * When the engine first reported speaking — and the only thing a play is counted by.
         *
         * Kept once set, through every later status. A session that completed, was stopped or failed
         * after it began all started, and all three count. One that never began has null here however
         * it ended, and counts for nothing.
         */
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    public function hasStarted(): bool
    {
        return $this->startedAt !== null;
    }
}
