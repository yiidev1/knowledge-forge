<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Client\CaseOrders\CaseOrderAuth;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Client\CaseOrders\HttpCaseOrderClient;
use App\Tests\Support\RecordingHttpClient;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function json_encode;
use function str_repeat;
use function strlen;

/**
 * The Case Order client. **No test here reaches the network.**
 *
 * The PSR-18 client is a double in every case, so "makes no live call" is structural rather than a
 * promise. That matters more here than anywhere else in this codebase: the client permits **one
 * request a day**, so a test that reached the provider would spend the day's allowance — and a suite
 * that ran twice would breach the agreement.
 *
 * ## What is really being guarded
 *
 * Two things, and neither is the happy path.
 *
 * 1. **Nothing unredacted leaves this class.** The response carries `card_num`, `exp_date` and a CVV
 *    for every credit-card order, and the body this client returns is *staged in the database* so the
 *    import can be retried. An unredacted return value would be a table of card numbers.
 * 2. **A refusal is distinguishable from an empty answer.** The import replaces an order's lines, so
 *    "the provider said nothing" and "the provider refused" must never arrive as the same thing.
 */
final class CaseOrderClientTest extends Unit
{
    // ------------------------------------------------------------------ the gate before any socket

    public function testAnUnconfiguredEndpointMakesNoRequest(): void
    {
        $http = $this->http(new Response(200, [], '[]'));

        try {
            $this->client($http, url: '', token: '')->fetch();
            $this->fail('An unconfigured endpoint must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            // Names the variables, never their values.
            $this->assertStringContainsString('ORDER58_CASE_ORDER_API_URL', $e->getMessage());
            $this->assertStringContainsString('ORDER58_CASE_ORDER_API_TOKEN', $e->getMessage());
            $this->assertFalse($e->spentTheAllowance, 'nothing was sent, so nothing was spent');
        }

        $this->assertNull($http->request, 'An unconfigured endpoint must not open a socket.');
    }

    /** Plain HTTP would put the credential and a customer's order on the wire in clear. */
    public function testAPlainHttpUrlIsRefused(): void
    {
        $http = $this->http(new Response(200, [], '[]'));

        try {
            $this->client($http, url: 'http://order58.com/api/x')->fetch();
            $this->fail('HTTP must be refused.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertStringContainsString('HTTPS', $e->getMessage());
        }

        $this->assertNull($http->request);
    }

    // ------------------------------------------------------------------ how the credential travels

    public function testQueryAuthPutsTheTokenInTheQueryString(): void
    {
        $http = $this->http(new Response(200, [], '[]'));

        $this->client($http, auth: CaseOrderAuth::Query)->fetch();

        $this->assertNotNull($http->request);
        $this->assertStringContainsString('access-token=secret-token', $http->query());
        $this->assertFalse(
            $http->request->hasHeader('Authorization'),
            'and not in a header as well — one credential, one place',
        );
    }

    public function testBearerAuthPutsTheTokenInTheHeaderAndNotTheUrl(): void
    {
        $http = $this->http(new Response(200, [], '[]'));

        $this->client($http, auth: CaseOrderAuth::Bearer)->fetch();

        $this->assertNotNull($http->request);
        $this->assertSame('Bearer secret-token', $http->request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('secret-token', $http->query(), 'never in the URL');
    }

    /** The provider's example posts an empty object: the endpoint answers for everything. */
    public function testItPostsAnEmptyJsonBody(): void
    {
        $http = $this->http(new Response(200, [], '[]'));

        $this->client($http)->fetch();

        $this->assertNotNull($http->request);
        $this->assertSame('POST', $http->request->getMethod());
        $this->assertSame('{}', (string) $http->request->getBody());
    }

    // ------------------------------------------------------------------ redaction

    /**
     * **No payment secret survives, at any depth, in anything this returns.**
     *
     * Asserted on the body that gets staged, because that is the one that is written to the database.
     * The sample carries the three keys twice — at the top of `data` and again inside `data.cc` — which
     * is why the redactor is recursive and why this checks the whole string rather than one path.
     */
    public function testNoPaymentSecretSurvivesInTheStagedBody(): void
    {
        $http = $this->http(new Response(200, [], json_encode([[
            'id' => 17066031,
            'account_id' => 1121,
            'data' => [
                'card_num' => '4111111111111111',
                'exp_date' => '01/2099',
                'cvv' => '123',
                'cc' => ['card_num' => '4111111111111111', 'cvv' => '123'],
                'reservation' => ['phone' => '15550000000'],
            ],
            'items' => [['id' => 1, 'order_id' => 17066031, 'name' => 'Wings', 'qty' => 1]],
        ]], JSON_THROW_ON_ERROR)));

        $response = $this->client($http)->fetch();

        foreach (['4111111111111111', '01/2099', 'card_num', 'exp_date', 'cvv'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $response->sanitizedBody,
                $secret . ' must not reach the staged payload',
            );
        }

        // And the order is still an order: redaction removed the secrets and nothing else.
        $this->assertStringContainsString('reservation', $response->sanitizedBody);
        $this->assertStringContainsString('Wings', $response->sanitizedBody);
        $this->assertCount(1, $response->orders);
    }

    /** A secret inside a LINE is removed too, which the recursive strip is what guarantees. */
    public function testASecretInsideALineIsRemoved(): void
    {
        $http = $this->http(new Response(200, [], json_encode([[
            'id' => 1, 'account_id' => 2,
            'items' => [['id' => 9, 'order_id' => 1, 'name' => 'X', 'data' => ['cvv' => '999']]],
        ]], JSON_THROW_ON_ERROR)));

        $this->assertStringNotContainsString('999', $this->client($http)->fetch()->sanitizedBody);
    }

    /** An envelope is tolerated, because `{"data": […]}` is how an API usually grows one. */
    public function testAWrappedResponseIsRead(): void
    {
        $http = $this->http(new Response(200, [], json_encode([
            'data' => [['id' => 1, 'account_id' => 2]],
        ], JSON_THROW_ON_ERROR)));

        $this->assertCount(1, $this->client($http)->fetch()->orders);
    }

    // ------------------------------------------------------------------ refusals, and what they cost

    /**
     * A refused request HAS been made, and the exception says so.
     *
     * The field matters more than the message: it is what decides whether the operator may try again
     * today or has to wait, and getting it wrong in the lenient direction means a second request.
     */
    public function testARefusalReportsTheAllowanceAsSpent(): void
    {
        try {
            $this->client($this->http(new Response(401, [], 'nope')))->fetch();
            $this->fail('A 401 must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertTrue($e->spentTheAllowance);
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringContainsString('401', $e->getMessage());
        }
    }

    /**
     * A 429 is carried out as a rate limit, with the provider's own `Retry-After`.
     *
     * This application no longer rations a manual press, so the provider's own word is the only
     * remaining restriction — it has to survive the trip out of the client intact.
     */
    public function testARateLimitCarriesTheProvidersRetryAfterInSeconds(): void
    {
        try {
            $this->client($this->http(new Response(429, ['Retry-After' => '120'], 'slow down')))->fetch();
            $this->fail('A 429 must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertTrue($e->spentTheAllowance, 'the request was made');
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(120, $e->retryAfterSeconds);
            $this->assertStringContainsString('too frequent', $e->getMessage());
        }
    }

    /** `Retry-After` is also legal as an HTTP date, and both forms must be honoured. */
    public function testARetryAfterGivenAsAnHttpDateIsRead(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', time() + 300);

        try {
            $this->client($this->http(new Response(429, ['Retry-After' => $at], '')))->fetch();
            $this->fail('A 429 must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertNotNull($e->retryAfterSeconds);
            // Allow a second of slack for the clock moving between the header and the parse.
            $this->assertGreaterThanOrEqual(295, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual(300, $e->retryAfterSeconds);
        }
    }

    /**
     * A 429 with no usable `Retry-After` reports the refusal and invents no wait.
     *
     * The absent header must stay absent. Substituting a default here would be this application
     * rationing itself again, under the provider's name — which is exactly what was removed.
     */
    public function testARateLimitWithoutAUsableRetryAfterInventsNoWait(): void
    {
        foreach (['', 'soon', '0', '-5', '99999999'] as $header) {
            try {
                $this->client($this->http(new Response(429, ['Retry-After' => $header], '')))->fetch();
                $this->fail('A 429 must refuse.');
            } catch (CaseOrderFetchFailed $e) {
                $this->assertSame(429, $e->httpStatus, 'header: ' . $header);
                $this->assertNull($e->retryAfterSeconds, 'header: ' . $header);
            }
        }
    }

    /** A transport failure has NOT been made, so the day is still available. */
    public function testAnUnreachableProviderLeavesTheAllowanceUnspent(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('boom') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->client($http)->fetch();
            $this->fail('An unreachable provider must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertFalse($e->spentTheAllowance);
            $this->assertStringContainsString('has not been used', $e->getMessage());
        }
    }

    /**
     * The message never carries the URL, because in query mode the URL carries the credential.
     *
     * This is the case that would leak it: a transport exception's own message routinely contains the
     * full request URI, and this message is stored in `error_summary` and rendered on an admin page.
     */
    public function testNoFailureMessageCarriesTheCredential(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                // Exactly what a real transport exception does: quote the whole URI back.
                throw new class ((string) $request->getUri()) extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->client($http)->fetch();
            $this->fail('An unreachable provider must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
            $this->assertStringNotContainsString('order58.com', $e->getMessage());
        }
    }

    public function testAnEmptyBodyIsRefusedRatherThanReadAsNoOrders(): void
    {
        $this->expectException(CaseOrderFetchFailed::class);
        $this->expectExceptionMessage('empty');

        $this->client($this->http(new Response(200, [], '')))->fetch();
    }

    public function testMalformedJsonIsRefusedAndNotQuotedBack(): void
    {
        try {
            $this->client($this->http(new Response(200, [], '{"id": ')))->fetch();
            $this->fail('Malformed JSON must refuse.');
        } catch (CaseOrderFetchFailed $e) {
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
            // A JSON error can quote the document it failed on, and the document is a customer's order.
            $this->assertStringNotContainsString('{"id"', $e->getMessage());
        }
    }

    /** An oversized response is abandoned rather than held, which is the point of the ceiling. */
    public function testAnOversizedResponseIsAbandoned(): void
    {
        $body = '[' . str_repeat('"x",', (int) (CaseOrderSettings::MAX_RESPONSE_BYTES / 4) + 16) . '"x"]';

        $this->assertGreaterThan(CaseOrderSettings::MAX_RESPONSE_BYTES, strlen($body));

        $this->expectException(CaseOrderFetchFailed::class);
        $this->expectExceptionMessage('MB limit');

        $this->client($this->http(new Response(200, [], $body)))->fetch();
    }

    // ------------------------------------------------------------------------------------ fixtures

    private function http(ResponseInterface $response): RecordingHttpClient
    {
        return new RecordingHttpClient($response);
    }

    private function client(
        ClientInterface $http,
        string $url = 'https://order58.com/api/order-data/get-case-order-mixed',
        string $token = 'secret-token',
        CaseOrderAuth $auth = CaseOrderAuth::Query,
    ): HttpCaseOrderClient {
        $factory = new HttpFactory();

        return new HttpCaseOrderClient(
            $http,
            $factory,
            $factory,
            new CaseOrderSettings(url: $url, token: $token, auth: $auth),
        );
    }
}
