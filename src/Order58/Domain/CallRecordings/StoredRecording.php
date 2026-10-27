<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/** A recording row as stored: where it was listed, and whether it still is. */
final readonly class StoredRecording
{
    public function __construct(
        public int $rowId,
        public int $position,
        public bool $listed,
    ) {}
}
