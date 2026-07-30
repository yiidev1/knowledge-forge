<?php

declare(strict_types=1);

namespace App\Ai\Contract\Dto;

/**
 * A request for an answer grounded in a knowledge base's vector store.
 *
 * The application assembles this (instructions built from the immutable security block plus the KB's
 * rules, bounded history, the question, and the store to search); the provider adapter translates it to
 * the wire format. `forceFileSearch` asks the provider to require retrieval rather than leave it
 * optional — the grounding guarantee depends on it.
 */
final readonly class GroundedAnswerRequest
{
    /**
     * @param list<ChatMessage> $history Prior turns, oldest first, already bounded by the caller.
     */
    public function __construct(
        public string $model,
        public string $instructions,
        public array $history,
        public string $question,
        public string $vectorStoreId,
        public int $maxResults,
        public int $maxOutputTokens,
        public bool $forceFileSearch = true,
        /**
         * Reasoning effort for models that reason before answering. Their reasoning tokens are billed
         * against `maxOutputTokens`, so a high effort on a broad question can exhaust the budget before
         * any answer text — and therefore any citation — has been produced.
         */
        public string $reasoningEffort = 'low',
    ) {}
}
