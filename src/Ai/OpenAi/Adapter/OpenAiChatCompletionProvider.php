<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Adapter;

use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;

/**
 * Translates a {@see GroundedAnswerRequest} into a Responses API call with a forced file-search tool,
 * and normalises the result.
 *
 * Grounding is requested at two levels: `tool_choice` forces retrieval, and `include` asks for the
 * search results so the application can verify a real retrieval happened. If a model rejects a forced
 * hosted-tool choice (a 400), the call is retried once with `auto`; the application's own grounding
 * check still decides whether the answer is acceptable, so an unforceable model cannot smuggle an
 * ungrounded answer through.
 */
final readonly class OpenAiChatCompletionProvider implements ChatCompletionProviderInterface
{
    public function __construct(
        private OpenAiClientInterface $client,
    ) {}

    public function ask(GroundedAnswerRequest $request): GroundedAnswerResult
    {
        $tools = [[
            'type' => 'file_search',
            'vector_store_ids' => [$request->vectorStoreId],
            'max_num_results' => $request->maxResults,
        ]];

        $input = $this->buildInput($request);

        $forced = $this->responseRequest($request, $input, $tools, ['type' => 'file_search']);

        try {
            $response = $this->client->createResponse($forced);
        } catch (AiProcessingFailed $e) {
            if (!$request->forceFileSearch) {
                throw $e;
            }
            // The model would not accept a forced file_search; fall back to auto and let the grounding
            // check downstream decide.
            $auto = $this->responseRequest($request, $input, $tools, 'auto');
            $response = $this->client->createResponse($auto);
        }

        return $this->toResult($response);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildInput(GroundedAnswerRequest $request): array
    {
        $input = [];
        foreach ($request->history as $message) {
            $input[] = ['role' => $message->role, 'content' => $message->text];
        }
        $input[] = ['role' => 'user', 'content' => $request->question];

        return $input;
    }

    /**
     * @param list<array<string, mixed>>      $input
     * @param list<array<string, mixed>>      $tools
     * @param array{type: string}|string      $toolChoice
     */
    private function responseRequest(
        GroundedAnswerRequest $request,
        array $input,
        array $tools,
        array|string $toolChoice,
    ): OpenAiResponseRequest {
        return new OpenAiResponseRequest(
            model: $request->model,
            input: $input,
            instructions: $request->instructions,
            tools: $tools,
            toolChoice: $toolChoice,
            // Ask for the retrieval results so grounding can be verified server-side.
            include: ['file_search_call.results'],
            maxOutputTokens: $request->maxOutputTokens,
            store: false,
            // Bound the thinking so the answer itself still fits in the same budget. Left unset, a broad
            // question spends the whole allowance reasoning and returns no citable text at all.
            reasoning: ['effort' => $request->reasoningEffort],
        );
    }

    private function toResult(OpenAiResponse $response): GroundedAnswerResult
    {
        return new GroundedAnswerResult(
            text: $response->outputText,
            retrievalCalled: $response->fileSearchCalled,
            retrievalStatus: $response->fileSearchStatus ?? 'not_called',
            retrievalResultCount: $response->fileSearchResultCount,
            topResultScore: $response->fileSearchTopScore,
            citations: $response->citations,
            usage: $response->usage,
            providerResponseId: $response->id,
            model: $response->model,
            // Carried rather than dropped: without these, downstream cannot tell a truncated answer
            // from an unsupported one, and the user is told the wrong thing.
            responseStatus: $response->status,
            incompleteReason: $response->incompleteReason,
            searchCallCount: $response->searchCallCount,
            completedSearchCallCount: $response->completedSearchCallCount,
        );
    }
}
