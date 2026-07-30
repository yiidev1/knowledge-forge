<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

/**
 * Resolved OpenAI tuning values, built once from the environment and injected — so nothing in the AI
 * layer reads env directly.
 */
final readonly class OpenAiParams
{
    public function __construct(
        public int $fileSearchMaxResults,
        public int $indexPollIntervalSeconds,
        public int $indexPollMaxSeconds,
        public int $operationMaxAttempts,
    ) {}
}
