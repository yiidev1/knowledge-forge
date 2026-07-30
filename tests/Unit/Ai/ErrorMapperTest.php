<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Contract\Exception\AiAuthenticationFailed;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\Contract\Exception\AiRateLimited;
use App\Ai\Contract\Exception\AiRequestTooLarge;
use App\Ai\Contract\Exception\AiTimeout;
use App\Ai\Contract\Exception\AiTransportFailed;
use App\Ai\OpenAi\ErrorMapper;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final class ErrorMapperTest extends Unit
{
    private ErrorMapper $mapper;

    protected function _before(): void
    {
        $this->mapper = new ErrorMapper(new SecretRedactor(['sk-secret-key-1234567890']));
    }

    public function test401IsNonRetryableAuthFailure(): void
    {
        $error = $this->mapper->fromResponse(new Response(401, [], '{}'), '{}');

        assertInstanceOf(AiAuthenticationFailed::class, $error);
        assertFalse($error->isTransient());
        assertFalse($error->isSideEffectPossible());
    }

    public function test429IsTransientWithRetryAfterAndNoSideEffect(): void
    {
        $error = $this->mapper->fromResponse(new Response(429, ['retry-after' => '7', 'x-request-id' => 'req-1'], '{}'), '{}');

        assertInstanceOf(AiRateLimited::class, $error);
        assertTrue($error->isTransient());
        assertFalse($error->isSideEffectPossible());
        assertSame(7, $error->retryAfterSeconds());
        assertSame('req-1', $error->requestId());
    }

    public function test413IsRequestTooLarge(): void
    {
        assertInstanceOf(AiRequestTooLarge::class, $this->mapper->fromResponse(new Response(413), ''));
    }

    public function test5xxIsTransientAndSideEffectPossible(): void
    {
        $error = $this->mapper->fromResponse(new Response(503), '');

        assertInstanceOf(AiTransportFailed::class, $error);
        assertTrue($error->isTransient());
        assertTrue($error->isSideEffectPossible(), 'the server received the request; outcome unknown');
    }

    public function testOther4xxIsRejectedWithNoSideEffect(): void
    {
        $error = $this->mapper->fromResponse(new Response(400, [], '{"error":{"message":"bad model"}}'), '{"error":{"message":"bad model"}}');

        assertInstanceOf(AiProcessingFailed::class, $error);
        assertFalse($error->isSideEffectPossible());
    }

    public function testNetworkExceptionIsConservativelyAmbiguous(): void
    {
        $networkError = new class ('boom') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new \GuzzleHttp\Psr7\Request('GET', 'https://api.openai.com/v1/x');
            }
        };

        $error = $this->mapper->fromTransportException($networkError);

        assertInstanceOf(AiTimeout::class, $error);
        assertTrue($error->isTransient());
        // A cURL timeout cannot tell connect from read, so it is treated as possibly effective.
        assertTrue($error->isSideEffectPossible());
    }

    /**
     * The provider's error body can echo the request, including a key. Whatever we surface must be
     * redacted.
     */
    public function testProviderMessageIsRedacted(): void
    {
        $body = '{"error":{"message":"invalid request with key sk-secret-key-1234567890 attached"}}';

        $error = $this->mapper->fromResponse(new Response(400, [], $body), $body);

        assertStringNotContainsString('sk-secret-key-1234567890', $error->getMessage());
    }
}
