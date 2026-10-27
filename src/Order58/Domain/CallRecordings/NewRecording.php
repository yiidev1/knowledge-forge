<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

use function hash;

/** A link to store: the exact URL, where it was listed, and the hash that identifies it. */
final readonly class NewRecording
{
    public string $urlHash;

    public function __construct(
        public int $position,
        public string $url,
    ) {
        $this->urlHash = self::hashOf($url);
    }

    /** The identity of a link. SHA-256 of the exact bytes, so two spellings are two links. */
    public static function hashOf(string $url): string
    {
        return hash('sha256', $url);
    }
}
