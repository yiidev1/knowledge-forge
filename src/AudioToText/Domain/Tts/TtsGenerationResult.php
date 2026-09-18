<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * What one successful generation produced, and what it cost.
 *
 * The cost figures are recorded because the alternative is an invoice nobody can reconcile against
 * anything. `characterCount` is what Deepgram bills on; `requestCount` is how many times the ceiling
 * forced a round trip. Neither is ever read to make a decision — they exist so the question "what did
 * this month's audio actually cost, and on which calls?" has an answer in the database rather than only
 * in a provider's dashboard.
 */
final readonly class TtsGenerationResult
{
    public function __construct(
        /** The published filename, already moved into place. */
        public string $fileName,
        public int $fileBytes,
        /** Characters sent to the provider — the billed quantity. */
        public int $characterCount,
        /** How many separate requests the per-request character ceiling forced. */
        public int $requestCount,
        /** Derived from the raw sample count, not probed: the rate was this application's own choice. */
        public float $durationSeconds,
    ) {}
}
