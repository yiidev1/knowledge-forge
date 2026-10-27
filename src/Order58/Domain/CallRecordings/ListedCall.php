<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * One call session as the provider listed it on one order: its id and its WAV links.
 *
 * The links are kept exactly as they arrived — the URL is the identity, and rebuilding one from a store
 * code or a date would produce a different link — in the provider's own order, with exact duplicates
 * removed so a repeated link cannot become a second recording.
 *
 * An empty list is a legitimate value and means "the provider said nothing about this session's files".
 * It is not read as "the files are gone": see {@see \App\Order58\Application\CallRecordings\CallRecordingCatalog}.
 */
final readonly class ListedCall
{
    /**
     * @param string       $sessionId the provider's `session_id`, digits only
     * @param list<string> $urls      in listing order, duplicates removed
     */
    public function __construct(
        public string $sessionId,
        public array $urls,
    ) {}
}
