<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Exception\AiAuthenticationFailed;
use App\Ai\Contract\Exception\AiTransportFailed;
use App\Ai\OpenAi\Client\HttpOpenAiClient;
use App\Ai\OpenAi\Client\MultipartFileUpload;
use App\Ai\OpenAi\Client\ResponseParser;
use App\Ai\OpenAi\Client\RetryPolicy;
use App\Ai\OpenAi\ErrorMapper;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\CapturingLogger;
use App\Tests\Support\Fake\Ai\FakePsr18Client;
use App\Tests\Support\Fake\Ai\RecordingSleeper;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Utils;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Drives the HTTP client against a fake PSR-18 transport: the retry matrix, and the guarantee that the
 * API key is transmitted but never logged.
 */
final class HttpOpenAiClientTest extends Unit
{
    private const KEY = 'sk-test-abcdef1234567890ghijkl';

    private FakePsr18Client $http;
    private RecordingSleeper $sleeper;
    private CapturingLogger $logger;

    protected function _before(): void
    {
        $this->http = new FakePsr18Client();
        $this->sleeper = new RecordingSleeper();
        $this->logger = new CapturingLogger();
    }

    private function client(int $maxRetries = 3): HttpOpenAiClient
    {
        $profile = new OpenAiHttpProfile('worker', 5, 30, $maxRetries, 60);
        $redactor = new SecretRedactor([self::KEY]);
        $factory = new HttpFactory();

        return new HttpOpenAiClient(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            credentials: new OpenAiCredentials(self::KEY, 'https://api.openai.com/v1', 'gpt-x', 'gpt-x'),
            profile: $profile,
            retryPolicy: new RetryPolicy($profile),
            parser: new ResponseParser(new ErrorMapper($redactor)),
            errorMapper: new ErrorMapper($redactor),
            multipart: new MultipartFileUpload(),
            sleeper: $this->sleeper,
            logger: $this->logger,
            logContext: new SafeLogContext($redactor, new CorrelationId('corr-1')),
        );
    }

    public function testSuccessfulRequestParsesAndSendsTheKey(): void
    {
        $this->http->queueResponse(200, '{"id":"vs-1","name":"n","status":"completed","metadata":{}}');

        $store = $this->client()->createVectorStore('n');

        assertSame('vs-1', $store->id);
        // The key is on the wire...
        assertSame('Bearer ' . self::KEY, $this->http->lastRequest()?->getHeaderLine('Authorization'));
    }

    /**
     * The single most important test in this phase: whatever happened over several attempts, the API key
     * must appear in nothing that was logged.
     */
    public function testApiKeyNeverAppearsInLogs(): void
    {
        // A 500 (retried) then a success, so both the error and success log paths run.
        $this->http->queueResponse(500, 'server error, request was Authorization: Bearer ' . self::KEY);
        $this->http->queueResponse(200, '{"id":"vs-1","name":"n","status":"completed","metadata":{}}');

        $this->client()->getVectorStoreFile('vs-1', 'file-1');

        assertStringNotContainsString(self::KEY, $this->logger->everything());
        assertStringNotContainsString('Bearer sk-', $this->logger->everything());
    }

    public function testRetriesTransientThenSucceeds(): void
    {
        $this->http->queueResponse(429, '{}', ['retry-after' => '0']);
        $this->http->queueResponse(200, '{"id":"file-1","vector_store_id":"vs-1","status":"completed"}');

        $file = $this->client()->getVectorStoreFile('vs-1', 'file-1');

        assertSame('file-1', $file->id);
        assertCount(2, $this->http->sentRequests, 'one retry after the 429');
        assertCount(1, $this->sleeper->sleeps);
    }

    public function testAuthFailureIsNotRetried(): void
    {
        $this->http->queueResponse(401, '{}');

        try {
            $this->client()->createVectorStore('n');
            $this->fail('Expected AiAuthenticationFailed.');
        } catch (AiAuthenticationFailed) {
            assertCount(1, $this->http->sentRequests, 'a 401 must not be retried');
            assertCount(0, $this->sleeper->sleeps);
        }
    }

    /**
     * A 5xx on a non-idempotent upload is not auto-retried: it might have taken effect, so it surfaces to
     * the ledger for reconciliation instead of risking a duplicate upload.
     */
    public function testServerErrorOnNonIdempotentUploadIsNotRetried(): void
    {
        $this->http->queueResponse(500, 'oops');

        try {
            $this->client()->uploadFile(Utils::streamFor('data'), 'a.pdf', 'assistants');
            $this->fail('Expected AiTransportFailed.');
        } catch (AiTransportFailed) {
            assertCount(1, $this->http->sentRequests, 'a non-idempotent 5xx is not retried');
        }
    }

    public function testServerErrorOnIdempotentReadIsRetried(): void
    {
        $this->http->queueResponse(500, 'oops');
        $this->http->queueResponse(200, '{"id":"file-1","vector_store_id":"vs-1","status":"completed"}');

        $this->client()->getVectorStoreFile('vs-1', 'file-1');

        assertCount(2, $this->http->sentRequests, 'an idempotent 5xx is retried');
    }

    public function testGivesUpAfterExhaustingRetries(): void
    {
        // maxRetries=2 → 3 total attempts, all 503.
        for ($i = 0; $i < 4; $i++) {
            $this->http->queueResponse(503, 'down');
        }

        try {
            $this->client(maxRetries: 2)->getVectorStoreFile('vs-1', 'file-1');
            $this->fail('Expected AiTransportFailed.');
        } catch (AiTransportFailed) {
            assertCount(3, $this->http->sentRequests, '1 initial + 2 retries');
        }
    }
}
