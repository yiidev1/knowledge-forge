<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * Markdown extracted from an image or scanned PDF by a vision model, with usage for cost accounting.
 */
final readonly class ExtractionResult
{
    public function __construct(
        public string $markdown,
        public TokenUsage $usage,
        public ?string $providerResponseId,
        public string $model,
    ) {}
}
