<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Client\HttpOrder58Client;
use App\Order58\Client\Order58Credentials;
use App\Order58\Client\Order58ErrorMapper;
use App\Order58\Client\Order58HttpProfile;
use App\Order58\Client\Order58ResponseParser;
use App\Order58\Client\Order58RetryPolicy;
use App\Order58\Contract\Exception\Order58AuthenticationFailed;
use App\Order58\Contract\Exception\Order58InvalidResponse;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\CapturingLogger;
use App\Tests\Support\Fake\Ai\FakePsr18Client;
use App\Tests\Support\Fake\Ai\RecordingSleeper;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\HttpFactory;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Drives the Order58 client against a fake PSR-18 transport: the Bearer token goes on the wire but never
 * into a log, pagination is parsed, and the retry matrix behaves.
 */
final class HttpOrder58ClientTest extends Unit
{
    private const TOKEN = 'kf-order58-secret-abcdef1234567890';
    private const BASE = 'https://order58.example/api/integration/v1';

    private FakePsr18Client $http;
    private RecordingSleeper $sleeper;
    private CapturingLogger $logger;

    protected function _before(): void
    {
        $this->http = new FakePsr18Client();
        $this->sleeper = new RecordingSleeper();
        $this->logger = new CapturingLogger();
    }

    private function client(int $maxRetries = 3): HttpOrder58Client
    {
        $redactor = new SecretRedactor([self::TOKEN]);
        $factory = new HttpFactory();
        $profile = new Order58HttpProfile('order58', 5, 30, $maxRetries, 60);

        return new HttpOrder58Client(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            credentials: new Order58Credentials(self::TOKEN, self::BASE),
            profile: $profile,
            retryPolicy: new Order58RetryPolicy($profile),
            parser: new Order58ResponseParser(new Order58ErrorMapper($redactor)),
            errorMapper: new Order58ErrorMapper($redactor),
            sleeper: $this->sleeper,
            logger: $this->logger,
            logContext: new SafeLogContext($redactor, new CorrelationId('corr-1')),
        );
    }

    private const ACCOUNTS_PAGE = '{"success":true,"data":[{"id":61,"name":"Test Store","company":"0004","active":0,"_sync_hash":"h61"}],"pagination":{"page":1,"per_page":10,"total":233,"total_pages":24},"meta":{"api_version":"v1"}}';

    public function testSendsBearerTokenAndParsesPagination(): void
    {
        $this->http->queueResponse(200, self::ACCOUNTS_PAGE);

        $page = $this->client()->listAccounts(1, 10);

        assertSame('Bearer ' . self::TOKEN, $this->http->lastRequest()?->getHeaderLine('Authorization'));
        assertSame('application/json', $this->http->lastRequest()?->getHeaderLine('Accept'));
        assertCount(1, $page->items);
        assertSame(61, $page->items[0]->id);
        assertSame(24, $page->pagination->totalPages);
        assertTrue($page->pagination->hasMore());
    }

    public function testTokenNeverAppearsInLogs(): void
    {
        // A 500 (retried, echoing the token in its body) then a success, so both log paths run.
        $this->http->queueResponse(500, 'server error, Authorization: Bearer ' . self::TOKEN);
        $this->http->queueResponse(200, self::ACCOUNTS_PAGE);

        $this->client()->listAccounts(1, 10);

        assertStringNotContainsString(self::TOKEN, $this->logger->everything());
        assertStringNotContainsString('Bearer ', $this->logger->everything());
    }

    public function testAuthFailureIsNotRetried(): void
    {
        $this->http->queueResponse(401, '{"success":false,"error":{"code":"UNAUTHENTICATED","message":"bad token"}}');

        try {
            $this->client()->listAccounts(1, 10);
            $this->fail('Expected Order58AuthenticationFailed.');
        } catch (Order58AuthenticationFailed) {
            assertCount(1, $this->http->sentRequests, 'a 401 must not be retried');
            assertCount(0, $this->sleeper->sleeps);
        }
    }

    public function testRateLimitIsRetriedThenSucceeds(): void
    {
        $this->http->queueResponse(429, '{}', ['retry-after' => '0']);
        $this->http->queueResponse(200, self::ACCOUNTS_PAGE);

        $page = $this->client()->listAccounts(1, 10);

        assertSame(61, $page->items[0]->id);
        assertCount(2, $this->http->sentRequests, 'one retry after the 429');
        assertCount(1, $this->sleeper->sleeps);
    }

    public function testServerErrorGivesUpAfterRetries(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->http->queueResponse(503, 'down');
        }

        try {
            $this->client(maxRetries: 2)->listAccounts(1, 10);
            $this->fail('Expected Order58TransportFailed.');
        } catch (Order58TransportFailed) {
            assertCount(3, $this->http->sentRequests, '1 initial + 2 retries');
        }
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->http->queueResponse(200, 'not json at all');

        $this->expectException(Order58InvalidResponse::class);
        $this->client()->listAccounts(1, 10);
    }

    public function testMissingRequiredIdIsRejected(): void
    {
        $this->http->queueResponse(200, '{"success":true,"data":[{"name":"no id","_sync_hash":"h"}],"pagination":{"page":1,"per_page":10,"total":1,"total_pages":1}}');

        $this->expectException(Order58InvalidResponse::class);
        $this->client()->listAccounts(1, 10);
    }

    public function testAuthenticateReturnsAgentOnSuccess(): void
    {
        $this->http->queueResponse(200, '{"success":true,"data":{"authenticated":true,"user":{"admin_id":139,"username":"agent","first_name":"agent","last_name":"1","email_address":"agent@test.com","status":"active","user_type":"agent","account_id":2,"account_ids":[2]}}}');

        $result = $this->client()->authenticate('agent', 'secret');

        assertTrue($result->authenticated);
        assertSame(139, $result->agent?->adminId);
        assertSame('agent', $result->agent?->userType);
        assertSame('POST', $this->http->lastRequest()?->getMethod());
    }

    /**
     * The live API answers a wrong password with **401**, not 200 — verified against
     * `https://order58.seawolf.io/api/integration/v1/authenticate`. This fixture used to queue a 200, which
     * is why the client's mapping of that 401 to a service-token failure went unnoticed: every wrong agent
     * password surfaced as "temporarily unavailable" and was never charged to the login throttle.
     */
    public function testAuthenticateRejectsWrongPasswordSentAsA401(): void
    {
        $this->http->queueResponse(401, '{"success":false,"error":{"code":"INVALID_CREDENTIALS","message":"Invalid username or password."}}');

        $result = $this->client()->authenticate('agent', 'wrong');

        assertFalse($result->authenticated);
        assertNull($result->agent);
    }

    /** Kept in case the upstream ever moves to the 200-with-success:false shape the docs once implied. */
    public function testAuthenticateStillRejectsWrongPasswordSentAs200(): void
    {
        $this->http->queueResponse(200, '{"success":false,"error":{"code":"INVALID_CREDENTIALS","message":"Invalid username or password."}}');

        assertFalse($this->client()->authenticate('agent', 'wrong')->authenticated);
    }

    /**
     * The other 401 on the same endpoint: our own Bearer token refused. This must stay an exception — if it
     * degraded to "wrong password" an expired token would blame every agent for it and, with the fallback
     * wired in, would silently move every login onto the weaker path.
     */
    public function testAuthenticateStillThrowsWhenOurOwnTokenIsRefused(): void
    {
        $this->http->queueResponse(401, '{"success":false,"error":{"code":"UNAUTHORIZED","message":"Authentication is required."}}');

        $this->expectException(Order58AuthenticationFailed::class);
        $this->client()->authenticate('agent', 'secret');
    }

    public function testAuthenticatePasswordNeverAppearsInLogs(): void
    {
        $this->http->queueResponse(200, '{"success":true,"data":{"authenticated":true,"user":{"admin_id":1,"username":"a","status":"active","user_type":"agent"}}}');

        $this->client()->authenticate('a', 'HunterTwo-Secret-99');

        assertStringNotContainsString('HunterTwo-Secret-99', $this->logger->everything());
    }

    public function testStoreScopedKnowledgeQueryCarriesStoreId(): void
    {
        $this->http->queueResponse(200, '{"success":true,"data":[],"pagination":{"page":1,"per_page":100,"total":0,"total_pages":1}}');

        $this->client()->listKnowledge(631, 1, 100);

        $target = (string) $this->http->lastRequest()?->getUri();
        assertTrue(str_contains($target, 'store_id=631'), 'scoped query must include store_id');
        assertFalse(str_contains($target, 'Bearer'), 'token is a header, never in the URL');
    }
}
