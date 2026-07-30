<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Dto;

use App\Ai\Contract\Dto\RawCitation;
use App\Ai\Contract\Dto\TokenUsage;

/**
 * A parsed OpenAI Responses API result, flattened to what the adapters need.
 *
 * The file-search fields are extracted from the `file_search_call` output items so the application can
 * verify grounding without re-walking the raw JSON: whether retrieval ran, its status, how many results
 * it returned, and the best score. A response may contain SEVERAL search calls — the counts are totals
 * across all of them and the status is the worst one seen, so a partial sweep is never reported as a
 * clean retrieval. Citations are only those actually present in the output annotations.
 */
final readonly class OpenAiResponse
{
    /**
     * @param list<RawCitation> $citations
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $outputText,
        public bool $fileSearchCalled,
        public ?string $fileSearchStatus,
        public int $fileSearchResultCount,
        public float $fileSearchTopScore,
        public array $citations,
        public TokenUsage $usage,
        public string $model,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        /**
         * Why generation stopped early, from `incomplete_details.reason` — `max_output_tokens` being
         * the one that matters here. A reasoning model spends the output budget on reasoning tokens
         * first, so a truncated response can arrive with retrieval evidence but no answer text and no
         * citation annotations, which is indistinguishable from "the documents do not support an
         * answer" unless this is carried.
         */
        public ?string $incompleteReason = null,
        /** Total `file_search_call` items in the response. */
        public int $searchCallCount = 0,
        /** How many of those reported `completed`. */
        public int $completedSearchCallCount = 0,
    ) {}
}
