<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Client;

use App\Ai\Contract\Exception\AiException;
use App\Ai\OpenAi\Dto\OpenAiFile;
use App\Ai\OpenAi\Dto\OpenAiResponse;
use App\Ai\OpenAi\Dto\OpenAiResponseRequest;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFile;
use App\Ai\OpenAi\Dto\OpenAiVectorStoreFilePage;
use App\Ai\OpenAi\Dto\OpenAiVectorStorePage;
use App\Ai\OpenAi\ErrorMapper;
use App\Ai\OpenAi\OpenAiCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Shared\Infrastructure\Log\SafeLogContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;

/**
 * The typed HTTP gateway to OpenAI, with retries, redacted logging and normalised errors.
 *
 * Two instances live in the container, one per {@see OpenAiHttpProfile} (chat vs worker), differing only
 * in the timeouts baked into their injected PSR-18 client and in their retry budget. The Authorization
 * header is the single place the API key is revealed; it is attached to the request and never logged —
 * only an allowlisted, redacted context ever reaches the logger.
 *
 * Idempotency is expressed per request: reads and deletes are safe to auto-retry; creates and uploads
 * are not, so a possibly-effective failure on those surfaces to the caller (the ledger) rather than
 * being resent.
 */
final readonly class HttpOpenAiClient implements OpenAiClientInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private OpenAiCredentials $credentials,
        private OpenAiHttpProfile $profile,
        private RetryPolicy $retryPolicy,
        private ResponseParser $parser,
        private ErrorMapper $errorMapper,
        private MultipartFileUpload $multipart,
        private SleeperInterface $sleeper,
        private LoggerInterface $logger,
        private SafeLogContext $logContext,
    ) {}

    public function uploadFile(
        StreamInterface $content,
        string $filename,
        string $purpose,
        ?string $idempotencyKey = null,
    ): OpenAiFile {
        $multipart = $this->multipart->build($content, $filename, $purpose);

        $request = $this->request('POST', '/files', $idempotencyKey)
            ->withHeader('Content-Type', $multipart['contentType'])
            ->withBody($multipart['stream']);

        return $this->parser->parseFile($this->send($request, 'files.upload', idempotent: false));
    }

    public function listFiles(string $purpose, int $limit = 100, ?string $after = null): array
    {
        $query = ['purpose' => $purpose, 'limit' => $limit];
        if ($after !== null) {
            $query['after'] = $after;
        }

        $request = $this->request('GET', '/files?' . http_build_query($query));

        return $this->parser->parseFileList($this->send($request, 'files.list', idempotent: true));
    }

    public function deleteFile(string $fileId): bool
    {
        $request = $this->request('DELETE', '/files/' . rawurlencode($fileId));

        return $this->deleted($this->send($request, 'files.delete', idempotent: true));
    }

    public function createVectorStore(string $name, array $metadata = [], ?string $idempotencyKey = null): OpenAiVectorStore
    {
        $body = ['name' => $name];
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $request = $this->jsonRequest('POST', '/vector_stores', $body, $idempotencyKey);

        return $this->parser->parseVectorStore($this->send($request, 'vector_stores.create', idempotent: false));
    }

    public function listVectorStores(int $limit = 100, ?string $after = null): array
    {
        return $this->listVectorStorePage($limit, $after)->data;
    }

    public function listVectorStorePage(int $limit = 100, ?string $after = null): OpenAiVectorStorePage
    {
        // `order=asc` so that a store created part-way through a sweep lands *after* the cursor and is
        // picked up on a later page, rather than being inserted ahead of it and skipped. Nothing
        // downstream depends on the ordering.
        $query = ['limit' => $limit, 'order' => 'asc'];
        if ($after !== null) {
            $query['after'] = $after;
        }

        $request = $this->request('GET', '/vector_stores?' . http_build_query($query));

        return $this->parser->parseVectorStorePage($this->send($request, 'vector_stores.list', idempotent: true));
    }

    public function getVectorStore(string $vectorStoreId): OpenAiVectorStore
    {
        $request = $this->request('GET', '/vector_stores/' . rawurlencode($vectorStoreId));

        return $this->parser->parseVectorStore($this->send($request, 'vector_stores.get', idempotent: true));
    }

    public function listVectorStoreFilePage(
        string $vectorStoreId,
        int $limit = 100,
        ?string $after = null,
        ?string $filter = null,
    ): OpenAiVectorStoreFilePage {
        $query = ['limit' => $limit, 'order' => 'asc'];
        if ($after !== null) {
            $query['after'] = $after;
        }
        if ($filter !== null) {
            $query['filter'] = $filter;
        }

        $request = $this->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorStoreId) . '/files?' . http_build_query($query),
        );

        return $this->parser->parseVectorStoreFilePage(
            $this->send($request, 'vector_stores.files.list', idempotent: true),
        );
    }

    public function deleteVectorStore(string $vectorStoreId): bool
    {
        $request = $this->request('DELETE', '/vector_stores/' . rawurlencode($vectorStoreId));

        return $this->deleted($this->send($request, 'vector_stores.delete', idempotent: true));
    }

    public function attachFileToVectorStore(
        string $vectorStoreId,
        string $fileId,
        array $attributes = [],
        ?string $idempotencyKey = null,
    ): OpenAiVectorStoreFile {
        $body = ['file_id' => $fileId];
        if ($attributes !== []) {
            $body['attributes'] = $attributes;
        }

        $request = $this->jsonRequest(
            'POST',
            '/vector_stores/' . rawurlencode($vectorStoreId) . '/files',
            $body,
            $idempotencyKey,
        );

        return $this->parser->parseVectorStoreFile($this->send($request, 'vector_stores.attach', idempotent: false));
    }

    public function getVectorStoreFile(string $vectorStoreId, string $fileId): OpenAiVectorStoreFile
    {
        $request = $this->request(
            'GET',
            '/vector_stores/' . rawurlencode($vectorStoreId) . '/files/' . rawurlencode($fileId),
        );

        return $this->parser->parseVectorStoreFile($this->send($request, 'vector_stores.file', idempotent: true));
    }

    public function detachFileFromVectorStore(string $vectorStoreId, string $fileId): bool
    {
        $request = $this->request(
            'DELETE',
            '/vector_stores/' . rawurlencode($vectorStoreId) . '/files/' . rawurlencode($fileId),
        );

        return $this->deleted($this->send($request, 'vector_stores.detach', idempotent: true));
    }

    public function createResponse(OpenAiResponseRequest $request): OpenAiResponse
    {
        $httpRequest = $this->jsonRequest('POST', '/responses', $request->toArray());

        // A duplicate response only wastes tokens (we keep our own history), so a retry is acceptable.
        return $this->parser->parseResponse($this->send($httpRequest, 'responses.create', idempotent: true));
    }

    /**
     * Sends a request, retrying transient failures per the policy, and returns the decoded JSON body.
     *
     * @return array<array-key, mixed>
     *
     * @throws AiException
     */
    private function send(RequestInterface $request, string $endpoint, bool $idempotent): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->sendRequest($request);
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                if ($status >= 200 && $status < 300) {
                    $this->log($endpoint, [
                        'status' => $status,
                        'openai_request_id' => $response->getHeaderLine('x-request-id') ?: null,
                        'attempt' => $attempt + 1,
                    ]);

                    return $this->decode($body, $response);
                }

                $error = $this->errorMapper->fromResponse($response, $body);
            } catch (ClientExceptionInterface $transportError) {
                $error = $this->errorMapper->fromTransportException($transportError);
            }

            $attempt++;
            $delay = $this->retryPolicy->decideDelay($attempt, $error->details(), $idempotent);
            $this->log($endpoint, $error->details()->toLogContext() + ['attempt' => $attempt]);

            if ($delay === null) {
                throw $error;
            }

            $this->sleeper->sleep($delay);
            $this->rewind($request);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $body, \Psr\Http\Message\ResponseInterface $response): array
    {
        if ($body === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw $this->errorMapper->invalidResponse('response body was not a JSON object', $response);
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function deleted(array $data): bool
    {
        return ($data['deleted'] ?? false) === true;
    }

    private function request(string $method, string $path, ?string $idempotencyKey = null): RequestInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->credentials->baseUrl . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->credentials->apiKey->reveal())
            ->withHeader('Accept', 'application/json');

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body, ?string $idempotencyKey = null): RequestInterface
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->request($method, $path, $idempotencyKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($json));
    }

    /**
     * Rewind a seekable request body so a retry re-sends the same bytes.
     */
    private function rewind(RequestInterface $request): void
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $endpoint, array $context): void
    {
        $this->logger->info(
            'openai request',
            $this->logContext->build(['endpoint' => $this->profile->name . ':' . $endpoint] + $context),
        );
    }
}
