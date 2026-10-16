<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/** Which store, order and channel one transcription job belongs to. */
final readonly class RecordingIdentity
{
    public function __construct(
        public string $jobPublicId,
        public int $storeSourceId,
        /** Null where the recording named no order, or named one that is not a plain number. */
        public ?int $sourceOrderId,
        /** MIXED, CALLER, CALLEE — as stored, or null where the upload named no channel. */
        public ?string $recordingType,
    ) {}
}
