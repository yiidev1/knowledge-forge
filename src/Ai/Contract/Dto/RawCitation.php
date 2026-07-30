<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * A citation exactly as the provider reported it, before the application resolves the provider file id
 * back to a knowledge-base document.
 *
 * Only present when the provider actually returned it — the adapter never fabricates one. The `fileId`
 * is the provider's opaque identifier; `filename` is whatever the provider stored, which for a derived
 * Markdown artifact is NOT the original upload name (the resolver fixes that in Phase 7).
 */
final readonly class RawCitation
{
    public function __construct(
        public string $fileId,
        public string $filename,
        public int $index,
    ) {}
}
