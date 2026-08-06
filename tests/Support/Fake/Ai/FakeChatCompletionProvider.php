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

    /** @var list<string> The vector store ids ask() was called with, in order (stage order). */
    public array $askedVectorStoreIds = [];

    private GroundedAnswerResult $result;

    private ?AiException $throw = null;

    /** @var array<string, GroundedAnswerResult> Per-vector-store scripted results (two-stage tests). */
    private array $resultsByStore = [];

    /** @var array<string, AiException> Per-vector-store scripted failures. */
    private array $throwByStore = [];

    public function __construct()
    {
        $this->result = self::grounded('An answer.', [new RawCitation('file_1', 'doc.md', 0)]);
    }

    public function setResult(GroundedAnswerResult $result): void
    {
        $this->result = $result;
    }

    public function setResultForStore(string $vectorStoreId, GroundedAnswerResult $result): void
    {
        $this->resultsByStore[$vectorStoreId] = $result;
    }

    public function throwForStore(string $vectorStoreId, AiException $exception): void
    {
        $this->throwByStore[$vectorStoreId] = $exception;
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
        $this->askedVectorStoreIds[] = $request->vectorStoreId;

        if (isset($this->throwByStore[$request->vectorStoreId])) {
            throw $this->throwByStore[$request->vectorStoreId];
        }
        if (isset($this->resultsByStore[$request->vectorStoreId])) {
            return $this->resultsByStore[$request->vectorStoreId];
        }
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
