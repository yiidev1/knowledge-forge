<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Dto\ChatMessage;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\RawCitation;
use App\Ai\Contract\Dto\TokenUsage;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\OpenAi\Adapter\OpenAiChatCompletionProvider;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Tests\Support\Fake\Ai\FakeOpenAiClient;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class OpenAiChatCompletionProviderTest extends Unit
{
    private FakeOpenAiClient $client;
    private OpenAiChatCompletionProvider $provider;

    protected function _before(): void
    {
        $this->client = new FakeOpenAiClient();
        $this->provider = new OpenAiChatCompletionProvider($this->client);
    }

    private function request(): GroundedAnswerRequest
    {
        return new GroundedAnswerRequest(
            model: 'gpt-x',
            instructions: 'be grounded',
            history: [ChatMessage::user('hi'), ChatMessage::assistant('hello')],
            question: 'what is 42?',
            vectorStoreId: 'vs-1',
            maxResults: 8,
            maxOutputTokens: 500,
        );
    }

    /**
     * The retrieval contract as it actually goes on the wire.
     *
     * `reasoning.effort` is the one that regressed in production: on a reasoning model the thinking is
     * billed against `max_output_tokens`, so without it a broad question spends the whole budget
     * reasoning and returns no citable answer. The rest are asserted alongside it so a future refactor
     * cannot quietly drop forced retrieval or the result payload the grounding check depends on.
     */
    public function testRequestCarriesTheFullRetrievalContract(): void
    {
        $this->provider->ask($this->request());

        $sent = $this->client->responseRequests[0];

        assertSame(['effort' => 'low'], $sent->reasoning);
        assertSame(['file_search_call.results'], $sent->include);
        assertSame(['type' => 'file_search'], $sent->toolChoice);
        assertSame('file_search', $sent->tools[0]['type']);
        assertSame(['vs-1'], $sent->tools[0]['vector_store_ids']);
        assertSame(8, $sent->tools[0]['max_num_results']);
        assertSame(500, $sent->maxOutputTokens);
        assertFalse($sent->store);

        // And the same values survive serialisation to the JSON body.
        $body = $sent->toArray();
        assertSame(['effort' => 'low'], $body['reasoning']);
        assertSame(500, $body['max_output_tokens']);
    }

    public function testReasoningEffortIsTakenFromTheRequest(): void
    {
        $this->provider->ask(new GroundedAnswerRequest(
            model: 'gpt-x',
            instructions: 'be grounded',
            history: [],
            question: 'q',
            vectorStoreId: 'vs-1',
            maxResults: 20,
            maxOutputTokens: 4000,
            reasoningEffort: 'medium',
        ));

        assertSame(['effort' => 'medium'], $this->client->responseRequests[0]->reasoning);
        assertSame(20, $this->client->responseRequests[0]->tools[0]['max_num_results']);
    }

    /**
     * A truncated response must reach the application as such. Dropping the status is what made a
     * cut-off answer look identical to one the documents could not support.
     */
    public function testCarriesTruncationAndSearchCountsThrough(): void
    {
        $this->client->responseQueue[] = new OpenAiResponse(
            id: 'resp-trunc',
            status: 'incomplete',
            outputText: '',
            fileSearchCalled: true,
            fileSearchStatus: 'completed',
            fileSearchResultCount: 5,
            fileSearchTopScore: 0.8,
            citations: [],
            usage: new TokenUsage(4000, 1200, 5200),
            model: 'gpt-x',
            incompleteReason: 'max_output_tokens',
            searchCallCount: 3,
            completedSearchCallCount: 3,
        );

        $result = $this->provider->ask($this->request());

        assertSame('incomplete', $result->responseStatus);
        assertSame('max_output_tokens', $result->incompleteReason);
        assertSame(3, $result->searchCallCount);
        assertSame(3, $result->completedSearchCallCount);
        assertTrue($result->wasTruncated());
    }

    public function testMapsResponseToGroundedResult(): void
    {
        $this->client->responseQueue[] = new OpenAiResponse(
            id: 'resp-1',
            status: 'completed',
            outputText: 'It is 42.',
            fileSearchCalled: true,
            fileSearchStatus: 'completed',
            fileSearchResultCount: 3,
            fileSearchTopScore: 0.88,
            citations: [new RawCitation('file-1', 'source.pdf', 0)],
            usage: new TokenUsage(10, 5, 15),
            model: 'gpt-x',
        );

        $result = $this->provider->ask($this->request());

        assertSame('It is 42.', $result->text);
        assertTrue($result->retrievalCalled);
        assertSame('completed', $result->retrievalStatus);
        assertSame(3, $result->retrievalResultCount);
        assertSame(0.88, $result->topResultScore);
        assertCount(1, $result->citations);
        assertSame('resp-1', $result->providerResponseId);
    }

    public function testNoRetrievalReportedAsNotCalled(): void
    {
        $this->client->responseQueue[] = new OpenAiResponse(
            'resp-2',
            'completed',
            'hi',
            false,
            null,
            0,
            0.0,
            [],
            TokenUsage::zero(),
            'gpt-x',
        );

        $result = $this->provider->ask($this->request());

        assertSame('not_called', $result->retrievalStatus);
    }

    /**
     * If the model rejects a forced hosted-tool choice (a 400), the provider retries once with auto.
     */
    public function testFallsBackToAutoWhenForcedToolChoiceRejected(): void
    {
        $this->client->responseQueue[] = new AiProcessingFailed(AiErrorDetails::of('request_rejected', 'tool_choice not supported', 400));
        $this->client->responseQueue[] = new OpenAiResponse(
            'resp-3',
            'completed',
            'answer',
            true,
            'completed',
            1,
            0.5,
            [],
            TokenUsage::zero(),
            'gpt-x',
        );

        $result = $this->provider->ask($this->request());

        assertSame('answer', $result->text);
        assertSame(['createResponse', 'createResponse'], $this->client->calls, 'forced then auto');
    }
}
