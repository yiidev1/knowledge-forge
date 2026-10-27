<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * A session row as stored, with its recordings, for comparison against a fresh listing.
 */
final readonly class StoredCallSession
{
    /**
     * @param array<string, StoredRecording> $recordings keyed by the SHA-256 of the URL
     */
    public function __construct(
        public int $rowId,
        public string $sessionId,
        public array $recordings,
    ) {}
}
