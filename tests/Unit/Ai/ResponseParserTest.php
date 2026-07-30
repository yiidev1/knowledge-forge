<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Exception\AiInvalidResponse;
use App\Ai\OpenAi\Client\ResponseParser;
use App\Ai\OpenAi\ErrorMapper;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class ResponseParserTest extends Unit
{
    private ResponseParser $parser;

    protected function _before(): void
    {
        $this->parser = new ResponseParser(new ErrorMapper(new SecretRedactor()));
    }

    public function testParsesFile(): void
    {
        $file = $this->parser->parseFile(['id' => 'file-1', 'filename' => 'a.pdf', 'purpose' => 'assistants', 'bytes' => 42, 'created_at' => 100]);

        assertSame('file-1', $file->id);
        assertSame('a.pdf', $file->filename);
        assertSame(42, $file->bytes);
    }

    public function testParsesVectorStoreFileWithLastError(): void
    {
        $vsf = $this->parser->parseVectorStoreFile([
            'id' => 'file-9',
            'vector_store_id' => 'vs-1',
            'status' => 'failed',
            'last_error' => ['code' => 'unsupported_file', 'message' => 'nope'],
            'usage_bytes' => 5,
        ]);

        assertSame('file-9', $vsf->id);
        assertSame('failed', $vsf->status);
        assertSame('unsupported_file', $vsf->lastErrorCode);
        assertSame(5, $vsf->usageBytes);
    }

    /**
     * A realistic Responses payload: a file_search_call item with results, then a message with text and
     * a file_citation annotation, plus usage.
     */
    public function testParsesGroundedResponse(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-1',
            'status' => 'completed',
            'model' => 'gpt-x',
            'output' => [
                [
                    'type' => 'file_search_call',
                    'status' => 'completed',
                    'results' => [
                        ['file_id' => 'file-1', 'score' => 0.7, 'text' => 'chunk'],
                        ['file_id' => 'file-2', 'score' => 0.9, 'text' => 'chunk'],
                    ],
                ],
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'The answer is 42.',
                            'annotations' => [
                                ['type' => 'file_citation', 'file_id' => 'file-1', 'filename' => 'source.pdf', 'index' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]);

        assertSame('resp-1', $response->id);
        assertSame('The answer is 42.', $response->outputText);
        assertTrue($response->fileSearchCalled);
        assertSame('completed', $response->fileSearchStatus);
        assertSame(2, $response->fileSearchResultCount);
        assertSame(0.9, $response->fileSearchTopScore);
        assertCount(1, $response->citations);
        assertSame('file-1', $response->citations[0]->fileId);
        assertSame('source.pdf', $response->citations[0]->filename);
        assertSame(15, $response->usage->totalTokens);
    }

    public function testResponseWithoutFileSearchCallReportsNotCalled(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-2',
            'status' => 'completed',
            'model' => 'gpt-x',
            'output' => [
                ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => 'hi', 'annotations' => []],
                ]],
            ],
        ]);

        assertFalse($response->fileSearchCalled);
        assertSame(0, $response->fileSearchResultCount);
        assertCount(0, $response->citations);
    }

    public function testMissingIdRaisesInvalidResponse(): void
    {
        $this->expectException(AiInvalidResponse::class);

        $this->parser->parseResponse(['status' => 'completed', 'output' => []]);
    }

    /**
     * A broad question makes the model search several times. Totals must cover every call.
     *
     * The original bug: each `file_search_call` overwrote the previous one's count and score, so a final
     * "anything else?" sweep returning nothing reported zero results — and the grounding check discarded
     * an answer that several earlier searches had fully supported.
     */
    public function testAccumulatesResultsAcrossEveryFileSearchCall(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-multi',
            'status' => 'completed',
            'model' => 'gpt-x',
            'output' => [
                [
                    'type' => 'file_search_call',
                    'status' => 'completed',
                    'results' => [
                        ['file_id' => 'file-1', 'score' => 0.7],
                        ['file_id' => 'file-2', 'score' => 0.9],
                    ],
                ],
                [
                    'type' => 'file_search_call',
                    'status' => 'completed',
                    'results' => [['file_id' => 'file-3', 'score' => 0.4]],
                ],
                // The empty trailing sweep that used to wipe out everything above.
                ['type' => 'file_search_call', 'status' => 'completed', 'results' => []],
            ],
        ]);

        assertSame(3, $response->fileSearchResultCount);
        assertSame(0.9, $response->fileSearchTopScore);
        assertSame(3, $response->searchCallCount);
        assertSame(3, $response->completedSearchCallCount);
        assertSame('completed', $response->fileSearchStatus);
    }

    /**
     * One completed call must never mask a sibling that failed — otherwise a partial sweep is reported
     * as a clean retrieval and the answer is trusted on incomplete evidence.
     */
    public function testWorstSearchStatusWins(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-mixed',
            'status' => 'completed',
            'output' => [
                ['type' => 'file_search_call', 'status' => 'completed', 'results' => [['file_id' => 'f', 'score' => 0.5]]],
                ['type' => 'file_search_call', 'status' => 'failed', 'results' => []],
                ['type' => 'file_search_call', 'status' => 'completed', 'results' => []],
            ],
        ]);

        assertSame('failed', $response->fileSearchStatus);
        assertSame(3, $response->searchCallCount);
        assertSame(2, $response->completedSearchCallCount);
    }

    public function testUnknownSearchStatusIsNotUpgradedToCompleted(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-unknown',
            'status' => 'completed',
            'output' => [
                ['type' => 'file_search_call', 'status' => 'completed', 'results' => []],
                ['type' => 'file_search_call', 'status' => 'something_new', 'results' => []],
            ],
        ]);

        assertSame('something_new', $response->fileSearchStatus);
    }

    /**
     * A reasoning model that spends its whole output budget thinking returns `incomplete` with retrieval
     * evidence but no message item — no text, no annotations. Carrying the reason is what lets the app
     * tell the user "the answer was cut short" instead of "I could not find enough information".
     */
    public function testParsesIncompleteDetailsReason(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-truncated',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [
                [
                    'type' => 'file_search_call',
                    'status' => 'completed',
                    'results' => [['file_id' => 'file-1', 'score' => 0.8]],
                ],
            ],
            'usage' => ['input_tokens' => 4000, 'output_tokens' => 1200, 'total_tokens' => 5200],
        ]);

        assertSame('incomplete', $response->status);
        assertSame('max_output_tokens', $response->incompleteReason);
        assertSame('', $response->outputText);
        assertCount(0, $response->citations);
        assertTrue($response->fileSearchCalled);
        assertSame(1, $response->fileSearchResultCount);
    }

    public function testCollectsAnnotationsFromEveryMessageAndContentPart(): void
    {
        $response = $this->parser->parseResponse([
            'id' => 'resp-many',
            'status' => 'completed',
            'output' => [
                ['type' => 'file_search_call', 'status' => 'completed', 'results' => [['file_id' => 'a', 'score' => 0.5]]],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'First. ',
                            'annotations' => [
                                ['type' => 'file_citation', 'file_id' => 'file-1', 'filename' => 'a.pdf', 'index' => 0],
                                ['type' => 'file_citation', 'file_id' => 'file-2', 'filename' => 'b.pdf', 'index' => 1],
                            ],
                        ],
                        [
                            'type' => 'output_text',
                            'text' => 'Second.',
                            'annotations' => [
                                ['type' => 'file_citation', 'file_id' => 'file-3', 'filename' => 'c.pdf', 'index' => 2],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        assertSame('First. Second.', $response->outputText);
        assertCount(3, $response->citations);
    }

    public function testMalformedFieldsDegradeGracefully(): void
    {
        // Wrong types everywhere except the required id: nothing throws, values default.
        $response = $this->parser->parseResponse([
            'id' => 'resp-3',
            'status' => 123,
            'output' => 'not-an-array',
            'usage' => 'nope',
        ]);

        assertSame('resp-3', $response->id);
        assertSame('', $response->outputText);
        assertSame(0, $response->usage->totalTokens);
    }
}
