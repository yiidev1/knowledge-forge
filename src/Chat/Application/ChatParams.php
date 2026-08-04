<?php

declare(strict_types=1);

namespace App\Chat\Application;

/**
 * Resolved chat tuning values, built once from the environment and injected — so no chat service reads
 * env directly. History limits live on the history policy, not here.
 */
final readonly class ChatParams
{
    public function __construct(
        public string $model,
        public int $maxResults,
        public int $maxOutputTokens,
        public bool $forceFileSearch,
        public bool $requireCitations,
        public float $minCitationScore,
        public string $fallbackMessage,
        public int $maxQuestionLength,
        /**
         * Reasoning effort passed to the provider. Reasoning tokens are drawn from the same budget as
         * `maxOutputTokens`, so this is what keeps room for the answer itself.
         */
        public string $reasoningEffort = 'low',
        /**
         * Retrieval width for questions that ask for *every* match rather than one. A relevance search
         * sized for "find the record" cannot enumerate "find all records".
         */
        public int $exhaustiveMaxResults = 20,
        /**
         * Shown when the model was cut off mid-answer. Deliberately distinct from `fallbackMessage`:
         * "I could not find enough information" is untrue and unactionable when the real problem is that
         * the answer did not fit.
         */
        public string $truncatedMessage = 'The answer was cut short before it could be completed. '
            . 'Please ask for a narrower part of it — for example one section or one record at a time.',
        /**
         * How long after a question is asked it stays editable, in minutes, measured from its original
         * created_at on the server clock. Only the latest question in a thread is ever editable.
         */
        public int $editWindowMinutes = 20,
    ) {}
}
