<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

use App\Shared\Application\Correlation\CorrelationId;

use function array_key_exists;
use function in_array;

/**
 * Builds log context from an allowlist.
 *
 * Deny-listing fields is the wrong default: the day a new field appears in a provider response, a
 * deny-list quietly starts logging it. Only the keys named here are ever emitted, and every string
 * still passes through {@see SecretRedactor} on the way out.
 */
final readonly class SafeLogContext
{
    /**
     * Keys that may appear in a log record. Anything else is dropped without comment.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'correlation_id',
        'operation_key',
        'endpoint',
        'method',
        'status',
        'duration_ms',
        'attempt',
        'max_attempts',
        'openai_request_id',
        'openai_response_id',
        'model',
        'knowledge_base_id',
        'document_id',
        'indexed_file_id',
        'conversation_id',
        'message_id',
        'document_status',
        'index_status',
        'retrieval_status',
        'error_code',
        'error_message',
        'file_count',
        'result_count',
        'bytes',
        'exit_code',
        // Retrieval and grounding diagnostics. Every one of these is a count, a boolean, a short
        // enum-like string or a six-character id suffix — never document content, question text or a
        // credential. Anything not listed here is still dropped.
        'retrieval_called',
        'search_call_count',
        'completed_search_call_count',
        'top_score',
        'annotation_count',
        'resolved_citation_count',
        'unresolved_citation_count',
        'grounding_reason',
        'is_grounded',
        'response_status',
        'incomplete_reason',
        'output_tokens',
        'max_output_tokens',
        'max_results',
        'reasoning_effort',
        'exhaustive_intent',
        'vector_store_suffix',
        'openai_file_id',
        'reason',
        // Admin usage dashboard. Counts, booleans and short enum-like strings only — never a store
        // name, a file name or an identifier beyond the suffix already allowed above.
        'store_count',
        'page_count',
        'truncated',
        'has_more',
    ];

    public function __construct(
        private SecretRedactor $redactor,
        private CorrelationId $correlationId,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (in_array($key, self::ALLOWED, true)) {
                $safe[$key] = $value;
            }
        }

        if (!array_key_exists('correlation_id', $safe)) {
            $safe['correlation_id'] = $this->correlationId->value();
        }

        return $this->redactor->redactContext($safe);
    }
}
