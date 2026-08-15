<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Ai\OpenAi\Client\SleeperInterface;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58Health;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Contract\Exception\Order58AuthenticationFailed;
use App\Order58\Contract\Exception\Order58Exception;
use App\Shared\Infrastructure\Log\SafeLogContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;
use function rawurlencode;

use const JSON_THROW_ON_ERROR;

/**
 * The typed HTTP gateway to the Order58 Integration API, with bounded retries, redacted logging and
 * normalised errors.
 *
 * The Authorization header is the single place the Bearer token is revealed; it is attached to the
 * outgoing request and never logged — only an allowlisted, redacted context ever reaches the logger.
 * Every method here is a GET (safe to auto-retry on a transient failure); Phase 1 sends no bodies.
 */
final readonly class HttpOrder58Client implements Order58ClientInterface
{
    /** The provider's `error.code` for a rejected end-user password, as distinct from a rejected service token. */
    private const INVALID_CREDENTIALS_CODE = 'INVALID_CREDENTIALS';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Order58Credentials $credentials,
        private Order58HttpProfile $profile,
        private Order58RetryPolicy $retryPolicy,
        private Order58ResponseParser $parser,
        private Order58ErrorMapper $errorMapper,
        private SleeperInterface $sleeper,
        private LoggerInterface $logger,
        private SafeLogContext $logContext,
    ) {}

    public function health(): Order58Health
    {
        $request = $this->request('GET', '/health');

        return $this->parser->parseHealth($this->send($request, 'health'));
    }

    public function listAccounts(int $page, int $perPage): Order58Page
    {
        $request = $this->request('GET', '/accounts?' . http_build_query(['page' => $page, 'per_page' => $perPage]));

        return $this->parser->parseAccountsPage($this->send($request, 'accounts.list'));
    }

    public function getAccount(int $id): Order58Account
    {
        $request = $this->request('GET', '/accounts/' . rawurlencode((string) $id));

        return $this->parser->parseAccount($this->send($request, 'accounts.get'));
    }

    public function listAgents(int $page, int $perPage): Order58Page
    {
        $request = $this->request('GET', '/agents?' . http_build_query(['page' => $page, 'per_page' => $perPage]));

        return $this->parser->parseAgentsPage($this->send($request, 'agents.list'));
    }

    public function listKnowledge(?int $storeId, int $page, int $perPage): Order58Page
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($storeId !== null) {
            $query['store_id'] = $storeId;
        }

        $request = $this->request('GET', '/knowledge?' . http_build_query($query));

        return $this->parser->parseKnowledgePage($this->send($request, 'knowledge.list'));
    }

    public function getKnowledgeRecord(int $id): Order58KnowledgeRecord
    {
        $request = $this->request('GET', '/knowledge/' . rawurlencode((string) $id));

        return $this->parser->parseKnowledgeRecord($this->send($request, 'knowledge.get'));
    }

    public function listRules(int $page, int $perPage): Order58Page
    {
        $request = $this->request('GET', '/rules?' . http_build_query(['page' => $page, 'per_page' => $perPage]));

        return $this->parser->parseRulesPage($this->send($request, 'rules.list'));
    }

    public function getRule(int $id): Order58RuleRecord
    {
        $request = $this->request('GET', '/rules/' . rawurlencode((string) $id));

        return $this->parser->parseRule($this->send($request, 'rules.get'));
    }

    public function authenticate(string $username, #[SensitiveParameter] string $password): Order58AuthResult
    {
        // The password travels only in the JSON body; logging is endpoint/status only, so it is never
        // written anywhere. A wrong password comes back as an unauthenticated result, not an exception.
        $request = $this->jsonPost('/authenticate', ['username' => $username, 'password' => $password]);

        try {
            $envelope = $this->send($request, 'authenticate');
        } catch (Order58AuthenticationFailed $e) {
            // This endpoint answers a wrong *user* password with HTTP 401, exactly like a rejected service
            // token, so the generic mapper cannot tell them apart and classifies both as our problem. Only
            // the provider's own code separates them:
            //
            //   INVALID_CREDENTIALS -> the agent typed the wrong password  (a value, and throttleable)
            //   UNAUTHORIZED / …    -> ORDER58_API_TOKEN was refused       (an exception, not the agent's fault)
            //
            // Getting this wrong is not cosmetic: treating a wrong password as an outage means the login
            // throttle is never charged, leaving agent password guessing unbounded on our side.
            if ($e->details()->providerCode === self::INVALID_CREDENTIALS_CODE) {
                return Order58AuthResult::invalid();
            }

            throw $e;
        }

        return $this->parser->parseAuthenticate($envelope);
    }

    /**
     * Sends a request, retrying transient failures per the policy, and returns the decoded JSON envelope.
     *
     * @return array<array-key, mixed>
     *
     * @throws Order58Exception
     */
    private function send(RequestInterface $request, string $endpoint): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->sendRequest($request);
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                if ($status >= 200 && $status < 300) {
                    $this->log($endpoint, ['status' => $status, 'attempt' => $attempt + 1]);

                    return $this->decode($body, $response);
                }

                $error = $this->errorMapper->fromResponse($response, $body);
            } catch (ClientExceptionInterface $transportError) {
                $error = $this->errorMapper->fromTransportException($transportError);
            }

            $attempt++;
            $delay = $this->retryPolicy->decideDelay($attempt, $error->details());
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
    private function decode(string $body, ResponseInterface $response): array
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

    private function request(string $method, string $path): RequestInterface
    {
        return $this->requestFactory
            ->createRequest($method, $this->credentials->baseUrl . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->credentials->token->reveal())
            ->withHeader('Accept', 'application/json');
    }

    /**
     * @param array<string, string> $body
     */
    private function jsonPost(string $path, array $body): RequestInterface
    {
        return $this->request('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * Rewinds a seekable request body so a retry re-sends the same bytes (matters for the POST body).
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
            'order58 request',
            $this->logContext->build(['endpoint' => $this->profile->name . ':' . $endpoint] + $context),
        );
    }
}
