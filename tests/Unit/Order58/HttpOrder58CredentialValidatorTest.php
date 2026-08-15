<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Client\HttpOrder58CredentialValidator;
use App\Order58\Client\Order58ValidateCredentials;
use App\Order58\Contract\Dto\Order58ValidationOutcome;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\CapturingLogger;
use App\Tests\Support\Fake\Ai\FakePsr18Client;
use Codeception\Test\Unit;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;

use function json_decode;
use function str_repeat;
use function strlen;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The fallback validate client against a fake transport.
 *
 * The contract worth pinning: an integer `status` of 200 and nothing else means "valid", our own rejected
 * token is never reported as the user's bad password, and untrusted upstream text is reduced to something
 * safe to put on a login page. `account_id` is deliberately never read — there is no assertion for it here
 * because there is no code path that could produce one.
 */
final class HttpOrder58CredentialValidatorTest extends Unit
{
    private const TOKEN = 'kf-order58-validate-secret-abcdef1234567890';
    private const URL = 'https://order58.example/api/user/validate';

    private FakePsr18Client $http;
    private CapturingLogger $logger;

    protected function _before(): void
    {
        $this->http = new FakePsr18Client();
        $this->logger = new CapturingLogger();
    }

    private function validator(string $token = self::TOKEN, string $url = self::URL): HttpOrder58CredentialValidator
    {
        $factory = new HttpFactory();
        $redactor = new SecretRedactor([self::TOKEN]);

        return new HttpOrder58CredentialValidator(
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
            credentials: new Order58ValidateCredentials($token, $url),
            logger: $this->logger,
            logContext: new SafeLogContext($redactor, new CorrelationId('corr-1')),
        );
    }

    // ---------------------------------------------------------------- the request on the wire

    public function testSendsTheDocumentedRequestShape(): void
    {
        $this->http->queueResponse(200, '{"status":200,"name":"SUCCESS","message":"SUCCESS","code":1,"account_id":21}');

        $this->validator()->validate('judy', 'hunter2');

        $request = $this->http->lastRequest();
        assertSame('POST', $request?->getMethod());
        assertSame(self::URL, (string) $request?->getUri());
        assertSame('application/json', $request?->getHeaderLine('Content-Type'));
        assertSame('Bearer ' . self::TOKEN, $request?->getHeaderLine('Authorization'));

        // RAW JSON with exactly the two documented keys — never form-encoded, never a query parameter.
        assertSame(
            ['login' => 'judy', 'password' => 'hunter2'],
            json_decode((string) $request?->getBody(), true),
        );
    }

    public function testTokenIsAHeaderAndNeverInTheUrl(): void
    {
        $this->http->queueResponse(200, '{"status":200}');

        $this->validator()->validate('agent', 'secret');

        assertStringNotContainsString('Bearer', (string) $this->http->lastRequest()?->getUri());
        assertStringNotContainsString(self::TOKEN, (string) $this->http->lastRequest()?->getUri());
    }

    public function testNeitherPasswordNorTokenReachesTheLog(): void
    {
        $this->http->queueResponse(500, 'error echoing Authorization: Bearer ' . self::TOKEN);

        $this->validator()->validate('agent', 'HunterTwo-Secret-99');

        assertStringNotContainsString('HunterTwo-Secret-99', $this->logger->everything());
        assertStringNotContainsString(self::TOKEN, $this->logger->everything());
        assertStringNotContainsString('Bearer ', $this->logger->everything());
    }

    // ---------------------------------------------------------------- strict success contract

    public function testIntegerStatus200IsTheOnlySuccess(): void
    {
        $this->http->queueResponse(200, '{"status":200,"name":"SUCCESS","message":"SUCCESS","code":1,"account_id":21}');

        $result = $this->validator()->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::Valid, $result->outcome);
        assertTrue($result->isValid());
        assertNull($result->safeMessage, 'the "SUCCESS" message is discarded, never shown');
    }

    public function testStringStatus200IsNotSuccess(): void
    {
        $this->http->queueResponse(200, '{"status":"200","account_id":21}');

        $result = $this->validator()->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::InvalidResponse, $result->outcome);
    }

    public function testSuccessNeedsNothingBesidesTheStatus(): void
    {
        // account_id is not required, because it is never read.
        $this->http->queueResponse(200, '{"status":200}');

        assertSame(Order58ValidationOutcome::Valid, $this->validator()->validate('a', 'b')->outcome);
    }

    public function testMissingStatusIsAnInvalidResponse(): void
    {
        $this->http->queueResponse(200, '{"name":"SUCCESS","code":1}');

        assertSame(Order58ValidationOutcome::InvalidResponse, $this->validator()->validate('a', 'b')->outcome);
    }

    // ---------------------------------------------------------------- classification

    public function testStatus400IsACredentialRejection(): void
    {
        $this->http->queueResponse(400, '{"name":"Bad Request","message":"Bad Request","code":0,"status":400}');

        $result = $this->validator()->validate('agent', 'wrong');

        assertSame(Order58ValidationOutcome::CredentialsRejected, $result->outcome);
        assertTrue($result->outcome->isCredentialVerdict());
        assertNull($result->safeMessage, 'a rejection uses our own wording, never the provider\'s');
    }

    /**
     * On this endpoint a 401/403 means *our* static token was refused. Reporting that as a bad password
     * would blame the agent for our configuration and would burn their login throttle.
     *
     * @dataProvider integrationAuthStatuses
     */
    public function testOurOwnRejectedTokenIsNotTheUsersProblem(int $status): void
    {
        $this->http->queueResponse($status, '{"status":' . $status . ',"message":"Unauthorized"}');

        $result = $this->validator()->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::AuthFailed, $result->outcome);
        assertSame(false, $result->outcome->isCredentialVerdict());
        assertNull($result->safeMessage, 'a token problem is never described to a visitor');
    }

    public function integrationAuthStatuses(): array
    {
        return [[401], [403]];
    }

    public function testServerErrorIsAnUpstreamError(): void
    {
        $this->http->queueResponse(500, '{"status":500,"message":"Internal Server Error"}');

        assertSame(Order58ValidationOutcome::UpstreamError, $this->validator()->validate('a', 'b')->outcome);
    }

    public function testMalformedJsonIsAnInvalidResponse(): void
    {
        $this->http->queueResponse(200, 'not json at all');

        assertSame(Order58ValidationOutcome::InvalidResponse, $this->validator()->validate('a', 'b')->outcome);
    }

    public function testEmptyBodyIsAnInvalidResponse(): void
    {
        $this->http->queueResponse(200, '');

        assertSame(Order58ValidationOutcome::InvalidResponse, $this->validator()->validate('a', 'b')->outcome);
    }

    public function testNetworkFailureIsANetworkError(): void
    {
        $this->http->queueException(new ConnectException('timed out', new Request('POST', self::URL)));

        $result = $this->validator()->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::NetworkError, $result->outcome);
        assertSame(false, $result->outcome->isCredentialVerdict());
    }

    /** @dataProvider incompleteConfigurations */
    public function testMissingConfigurationIsReportedWithoutCallingAnything(string $token, string $url): void
    {
        $result = $this->validator($token, $url)->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::NotConfigured, $result->outcome);
        assertSame([], $this->http->sentRequests, 'nothing may be sent when the fallback is not configured');
        assertStringNotContainsString(self::TOKEN, $this->logger->everything());
    }

    public function incompleteConfigurations(): array
    {
        return [
            'no token' => ['', self::URL],
            'no url' => [self::TOKEN, ''],
            'relative url' => [self::TOKEN, '/api/user/validate'],
        ];
    }

    // ---------------------------------------------------------------- untrusted upstream text

    public function testAUsableMessageOnAnUninterpretableErrorIsSurfaced(): void
    {
        $this->http->queueResponse(423, '{"status":423,"message":"Some API error"}');

        $result = $this->validator()->validate('agent', 'secret');

        assertSame(Order58ValidationOutcome::UpstreamError, $result->outcome);
        assertSame('Some API error', $result->safeMessage);
    }

    /** @dataProvider unusableMessages */
    public function testAnUnusableMessageYieldsNothingToShow(string $body): void
    {
        $this->http->queueResponse(423, $body);

        assertNull($this->validator()->validate('agent', 'secret')->safeMessage);
    }

    public function unusableMessages(): array
    {
        return [
            'absent' => ['{"status":423}'],
            'null' => ['{"status":423,"message":null}'],
            'not a string' => ['{"status":423,"message":123}'],
            'empty' => ['{"status":423,"message":""}'],
            'whitespace only' => ['{"status":423,"message":"   "}'],
            'control characters only' => ['{"status":423,"message":"\n\t\r"}'],
        ];
    }

    public function testControlCharactersAndNewlinesAreCollapsed(): void
    {
        $this->http->queueResponse(423, '{"status":423,"message":"Bad\n\n\tRequest"}');

        assertSame('Bad Request', $this->validator()->validate('agent', 'secret')->safeMessage);
    }

    public function testAnOverlongMessageIsTruncated(): void
    {
        $long = str_repeat('A', 300);
        $this->http->queueResponse(423, '{"status":423,"message":"' . $long . '"}');

        $message = $this->validator()->validate('agent', 'secret')->safeMessage;

        assertSame(str_repeat('A', 200) . '…', $message);
        assertTrue(strlen((string) $message) < 300);
    }

    /**
     * Markup is not stripped here — it is escaped at render time by the flash partial. What matters is that
     * it survives as inert text of bounded length rather than being treated as anything structural.
     */
    public function testMarkupIsCarriedAsPlainTextNotInterpreted(): void
    {
        $this->http->queueResponse(423, '{"status":423,"message":"<script>alert(1)</script>"}');

        assertSame('<script>alert(1)</script>', $this->validator()->validate('agent', 'secret')->safeMessage);
    }
}
