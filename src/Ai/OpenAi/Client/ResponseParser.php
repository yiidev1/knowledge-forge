<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

use App\Ai\Contract\Dto\RawCitation;
use App\Ai\Contract\Dto\TokenUsage;
use App\Ai\OpenAi\Dto\OpenAiFile;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFilePage;
use App\Ai\OpenAi\Dto\OpenAiVectorStorePage;
use App\Ai\OpenAi\Dto\VectorStoreFileCounts;
use App\Ai\OpenAi\ErrorMapper;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Turns decoded OpenAI JSON into typed DTOs, validating structure rather than trusting it.
 *
 * Every field is read through a type-checked accessor with a default; a missing required identifier
 * raises an invalid-response error instead of producing a null that fails later. This is the boundary
 * that keeps "do not assume API response fields" honest — nothing downstream touches raw arrays.
 */
final readonly class ResponseParser
{
    private const SEARCH_STATUS_COMPLETED = 'completed';

    public function __construct(
        private ErrorMapper $errorMapper,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public function parseFile(array $data): OpenAiFile
    {
        $id = $this->requireString($data, 'id');

        return new OpenAiFile(
            id: $id,
            filename: $this->string($data, 'filename'),
            purpose: $this->string($data, 'purpose'),
            bytes: $this->int($data, 'bytes'),
            createdAt: $this->int($data, 'created_at'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<OpenAiFile>
     */
    public function parseFileList(array $data): array
    {
        $result = [];
        foreach ($this->list($data, 'data') as $item) {
            if (is_array($item)) {
                $result[] = $this->parseFile($item);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function parseVectorStore(array $data): OpenAiVectorStore
    {
        /** @var array<string, mixed> $expiresAfter */
        $expiresAfter = $this->array($data, 'expires_after');

        return new OpenAiVectorStore(
            id: $this->requireString($data, 'id'),
            name: $this->string($data, 'name'),
            status: $this->string($data, 'status'),
            metadata: $this->stringMap($data, 'metadata'),
            createdAt: $this->int($data, 'created_at'),
            usageBytes: $this->int($data, 'usage_bytes'),
            fileCounts: VectorStoreFileCounts::fromArray($this->array($data, 'file_counts')),
            lastActiveAt: $this->nullableInt($data, 'last_active_at'),
            expiresAt: $this->nullableInt($data, 'expires_at'),
            expiresAfter: $expiresAfter === [] ? null : $expiresAfter,
        );
    }

    /**
     * One page of vector stores, keeping the cursor envelope the endpoint returns.
     *
     * @param array<array-key, mixed> $data
     */
    public function parseVectorStorePage(array $data): OpenAiVectorStorePage
    {
        return new OpenAiVectorStorePage(
            data: $this->parseVectorStoreList($data),
            hasMore: $this->bool($data, 'has_more'),
            lastId: $this->nullableString($data, 'last_id'),
        );
    }

    /**
     * One page of the files attached to a vector store.
     *
     * @param array<array-key, mixed> $data
     */
    public function parseVectorStoreFilePage(array $data): OpenAiVectorStoreFilePage
    {
        $files = [];
        foreach ($this->list($data, 'data') as $item) {
            if (is_array($item)) {
                $files[] = $this->parseVectorStoreFile($item);
            }
        }

        return new OpenAiVectorStoreFilePage(
            data: $files,
            hasMore: $this->bool($data, 'has_more'),
            lastId: $this->nullableString($data, 'last_id'),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<OpenAiVectorStore>
     */
    public function parseVectorStoreList(array $data): array
    {
        $result = [];
        foreach ($this->list($data, 'data') as $item) {
            if (is_array($item)) {
                $result[] = $this->parseVectorStore($item);
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function parseVectorStoreFile(array $data): OpenAiVectorStoreFile
    {
        $lastError = $this->array($data, 'last_error');

        return new OpenAiVectorStoreFile(
            id: $this->requireString($data, 'id'),
            vectorStoreId: $this->string($data, 'vector_store_id'),
            status: $this->string($data, 'status'),
            lastErrorCode: $lastError === [] ? null : $this->nullableString($lastError, 'code'),
            lastErrorMessage: $lastError === [] ? null : $this->nullableString($lastError, 'message'),
            usageBytes: isset($data['usage_bytes']) && is_numeric($data['usage_bytes']) ? (int) $data['usage_bytes'] : null,
            createdAt: $this->nullableInt($data, 'created_at'),
            // Only the strategy *type* is kept. The full object can carry chunk sizes and overlaps that
            // are of no use on a dashboard, and the point here is metadata, never content.
            chunkingStrategy: $this->nullableString($this->array($data, 'chunking_strategy'), 'type'),
            attributes: $this->stringMap($data, 'attributes'),
        );
    }

    /**
     * Parses a Responses API result, extracting answer text, retrieval evidence and citations from the
     * `output` items.
     *
     * @param array<array-key, mixed> $data
     */
    public function parseResponse(array $data): OpenAiResponse
    {
        $id = $this->requireString($data, 'id');

        $text = '';
        $citations = [];
        $fileSearchCalled = false;
        $fileSearchStatus = null;
        $fileSearchResultCount = 0;
        $fileSearchTopScore = 0.0;
        $searchCallCount = 0;
        $completedSearchCallCount = 0;

        foreach ($this->list($data, 'output') as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = $this->string($item, 'type');

            if ($type === 'file_search_call') {
                $fileSearchCalled = true;
                $searchCallCount++;

                $callStatus = $this->string($item, 'status');
                if ($callStatus === self::SEARCH_STATUS_COMPLETED) {
                    $completedSearchCallCount++;
                }
                // Worst status wins: one completed call must never mask a sibling that failed or is
                // still running, or a partial sweep would be reported as a clean retrieval.
                $fileSearchStatus = $this->worstSearchStatus($fileSearchStatus, $callStatus);

                // A broad question makes the model search several times. Totals must accumulate across
                // every call — taking only the last one discards the evidence the earlier calls found.
                [$callCount, $callTopScore] = $this->summariseResults($item);
                $fileSearchResultCount += $callCount;
                $fileSearchTopScore = max($fileSearchTopScore, $callTopScore);
                continue;
            }

            if ($type === 'message') {
                [$messageText, $messageCitations] = $this->parseMessage($item);
                $text .= $messageText;
                foreach ($messageCitations as $citation) {
                    $citations[] = $citation;
                }
            }
        }

        $error = $this->array($data, 'error');
        $incompleteDetails = $this->array($data, 'incomplete_details');

        return new OpenAiResponse(
            id: $id,
            status: $this->string($data, 'status'),
            outputText: $text,
            fileSearchCalled: $fileSearchCalled,
            fileSearchStatus: $fileSearchStatus,
            fileSearchResultCount: $fileSearchResultCount,
            fileSearchTopScore: $fileSearchTopScore,
            citations: $citations,
            usage: $this->parseUsage($this->array($data, 'usage')),
            model: $this->string($data, 'model'),
            errorCode: $error === [] ? null : $this->nullableString($error, 'code'),
            errorMessage: $error === [] ? null : $this->nullableString($error, 'message'),
            // Why the model stopped. Without this a truncated answer is indistinguishable from one the
            // documents genuinely could not support, and the user is told the wrong thing.
            incompleteReason: $incompleteDetails === [] ? null : $this->nullableString($incompleteDetails, 'reason'),
            searchCallCount: $searchCallCount,
            completedSearchCallCount: $completedSearchCallCount,
        );
    }

    /**
     * Folds one more `file_search_call` status into the running aggregate, worst first.
     *
     * The aggregate is `completed` only when every call completed. Anything the API reports that this
     * list does not know about is treated as worse than `completed`, so an unfamiliar status can never
     * be silently upgraded into a clean bill of health.
     */
    private function worstSearchStatus(?string $current, string $incoming): string
    {
        if ($current === null) {
            return $incoming;
        }

        return $this->searchStatusRank($incoming) < $this->searchStatusRank($current) ? $incoming : $current;
    }

    private function searchStatusRank(string $status): int
    {
        return match ($status) {
            'failed' => 0,
            'incomplete' => 1,
            'in_progress', 'searching' => 2,
            self::SEARCH_STATUS_COMPLETED => 4,
            default => 3,
        };
    }

    /**
     * @param array<array-key, mixed> $item
     *
     * @return array{0: int, 1: float}
     */
    private function summariseResults(array $item): array
    {
        $count = 0;
        $topScore = 0.0;

        foreach ($this->list($item, 'results') as $result) {
            if (!is_array($result)) {
                continue;
            }
            $count++;
            if (isset($result['score']) && is_numeric($result['score'])) {
                $topScore = max($topScore, (float) $result['score']);
            }
        }

        return [$count, $topScore];
    }

    /**
     * @param array<array-key, mixed> $message
     *
     * @return array{0: string, 1: list<RawCitation>}
     */
    private function parseMessage(array $message): array
    {
        $text = '';
        $citations = [];

        foreach ($this->list($message, 'content') as $content) {
            if (!is_array($content) || $this->string($content, 'type') !== 'output_text') {
                continue;
            }

            $text .= $this->string($content, 'text');

            foreach ($this->list($content, 'annotations') as $annotation) {
                if (!is_array($annotation) || $this->string($annotation, 'type') !== 'file_citation') {
                    continue;
                }

                $fileId = $this->nullableString($annotation, 'file_id');
                if ($fileId === null) {
                    continue;
                }

                $citations[] = new RawCitation(
                    fileId: $fileId,
                    filename: $this->string($annotation, 'filename'),
                    index: $this->int($annotation, 'index'),
                );
            }
        }

        return [$text, $citations];
    }

    /**
     * @param array<array-key, mixed> $usage
     */
    private function parseUsage(array $usage): TokenUsage
    {
        if ($usage === []) {
            return TokenUsage::zero();
        }

        return new TokenUsage(
            inputTokens: $this->int($usage, 'input_tokens'),
            outputTokens: $this->int($usage, 'output_tokens'),
            totalTokens: $this->int($usage, 'total_tokens'),
        );
    }

    // ---- Type-checked accessors -------------------------------------------

    /**
     * @param array<array-key, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw $this->errorMapper->invalidResponse("missing or empty field \"$key\"");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        // is_numeric() is already false for booleans, so no separate bool guard is needed.
        return is_int($value) || is_numeric($value) ? (int) $value : 0;
    }

    /**
     * An integer that is genuinely absent rather than zero.
     *
     * `last_active_at` and `expires_at` are nullable at the provider, and "never expires" is a different
     * fact from "expired at the epoch" — {@see int()} would flatten both to 0.
     *
     * @param array<array-key, mixed> $data
     */
    private function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }

    /**
     * A strict boolean: only a real `true` is true.
     *
     * Deliberately not truthiness. `has_more` drives a pagination loop, so a malformed or missing value
     * must stop the sweep rather than spin it.
     *
     * @param array<array-key, mixed> $data
     */
    private function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? null) === true;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private function stringMap(array $data, string $key): array
    {
        $map = [];
        foreach ($this->array($data, $key) as $k => $v) {
            if (is_string($v)) {
                $map[(string) $k] = $v;
            }
        }

        return $map;
    }
}
