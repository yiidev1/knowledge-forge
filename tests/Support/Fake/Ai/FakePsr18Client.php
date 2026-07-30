<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_shift;

/**
 * A programmable PSR-18 client for testing {@see \App\Ai\OpenAi\Client\HttpOpenAiClient} without a
 * network. Responses and transport exceptions are queued in the order they should be returned, and every
 * sent request is captured so a test can assert on headers (e.g. that the key is present on the wire but
 * never in a log).
 */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $sentRequests = [];

    /**
     * @param array<string, string> $headers
     */
    public function queueResponse(int $status, string $body = '', array $headers = []): void
    {
        $this->queue[] = new Response($status, $headers, $body);
    }

    public function queueException(ClientExceptionInterface $exception): void
    {
        $this->queue[] = $exception;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sentRequests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            return new Response(200, [], '{}');
        }
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): ?RequestInterface
    {
        $count = count($this->sentRequests);

        return $count === 0 ? null : $this->sentRequests[$count - 1];
    }
}
