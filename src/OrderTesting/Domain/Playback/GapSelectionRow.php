<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/** One standalone dropdown change, as the reporting shows it. */
final readonly class GapSelectionRow
{
    public function __construct(
        public int $gapMs,
        public ?string $recordingLabel,
        public string $selectedAt,
    ) {}
}
