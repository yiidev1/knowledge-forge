<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Dto\RawCitation;
use App\Ai\Contract\Dto\TokenUsage;
use App\Ai\Contract\Exception\AiException;

/**
 * Scriptable chat provider. Captures the request the ask service built (so a test can assert the
 * instructions and history), and returns a result the test dictates — or throws, to exercise the
 * provider-failure path.
 */
final class FakeChatCompletionProvider implements ChatCompletionProviderInterface
{
    public ?GroundedAnswerRequest $lastRequest = null;

    private GroundedAnswerResult $result;

    private ?AiException $throw = null;

    public function __construct()
    {
        $this->result = self::grounded('An answer.', [new RawCitation('file_1', 'doc.md', 0)]);
    }

    public function setResult(GroundedAnswerResult $result): void
    {
        $this->result = $result;
    }

    public function willThrow(AiException $exception): void
    {
        $this->throw = $exception;
    }

    /**
     * Stop throwing — for exercising a retry after a simulated provider failure.
     */
    public function stopThrowing(): void
    {
        $this->throw = null;
    }

    public function ask(GroundedAnswerRequest $request): GroundedAnswerResult
    {
        $this->lastRequest = $request;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result;
    }

    /**
     * @param list<RawCitation> $citations
     */
    public static function grounded(string $text, array $citations): GroundedAnswerResult
    {
        return new GroundedAnswerResult(
            text: $text,
            retrievalCalled: true,
            retrievalStatus: 'completed',
            retrievalResultCount: 3,
            topResultScore: 0.9,
            citations: $citations,
            usage: new TokenUsage(10, 20, 30),
            providerResponseId: 'resp_fake',
            model: 'fake-chat',
        );
    }

    public static function noRetrieval(string $text): GroundedAnswerResult
    {
        return new GroundedAnswerResult(
            text: $text,
            retrievalCalled: false,
            retrievalStatus: 'not_called',
            retrievalResultCount: 0,
            topResultScore: 0.0,
            citations: [],
            usage: new TokenUsage(5, 5, 10),
            providerResponseId: 'resp_fake',
            model: 'fake-chat',
        );
    }
}
