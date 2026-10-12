<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Client\Items\HttpOrderItemClient;
use App\Order58\Client\Items\OrderItemDataFailed;
use App\Order58\Client\Items\OrderItemSettings;
use App\Tests\Support\RecordingHttpClient;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function json_decode;
use function json_encode;
use function str_repeat;

/**
 * The Order Items API client. **No test here reaches the network.**
 *
 * The PSR-18 client is a double in every case, so "makes no live call" is structural rather than a
 * promise. That matters more than usual: the endpoint's authentication is unconfirmed, and probing a
 * production API to find out is exactly what this feature must not do.
 *
 * ## What is really being guarded
 *
 * This client's answer decides whether an order's stored items are **deleted and replaced**. So the
 * interesting cases are not the happy ones — they are every shape that could be mistaken for "this
 * order has no items" when it actually means "we do not know".
 */
final class OrderItemClientTest extends Unit
{
    private const ACCOUNT = 1731;
    private const ORDER = 16800401;

    // ------------------------------------------------------------------ the gates before any socket

    public function testADisabledFeatureMakesNoRequest(): void
    {
        $http = $this->http(new Response(200, [], '{"OrderItems":[]}'));
        $client = $this->client($http, enabled: false);

        $this->expectException(OrderItemDataFailed::class);

        try {
            $client->listItems(self::ACCOUNT, self::ORDER);
        } finally {
            $this->assertNull($http->request, 'A disabled feature must not open a socket.');
        }
    }

    public function testAnUnconfiguredEndpointMakesNoRequest(): void
    {
        $http = $this->http(new Response(200, [], '{"OrderItems":[]}'));
        $client = $this->client($http, url: '', token: '');

        try {
            $client->listItems(self::ACCOUNT, self::ORDER);
            $this->fail('An unconfigured endpoint must refuse.');
        } catch (OrderItemDataFailed $e) {
            // Names the variables, never their values.
            $this->assertStringContainsString('ORDER58_ITEM_API_URL', $e->getMessage());
            $this->assertStringContainsString('ORDER58_ITEM_API_TOKEN', $e->getMessage());
        }

        $this->assertNull($http->request);
    }

    // ------------------------------------------------------------------ the request that goes out

    public function testTheRequestCarriesTheOrderAndTheCredential(): void
    {
        $http = $this->http(new Response(200, [], '{"OrderItems":[]}'));
        $this->client($http)->listItems(self::ACCOUNT, self::ORDER);

        $request = $http->request;
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));

        // Strings, as the provider's own sample shows them.
        $this->assertSame(
            ['account_id' => '1731', 'order_id' => '16800401'],
            json_decode((string) $request->getBody(), true),
        );
    }

    /** The scheme is configuration, because nobody has confirmed what this endpoint wants. */
    public function testAnEmptyAuthSchemeSendsTheTokenBare(): void
    {
        $http = $this->http(new Response(200, [], '{"OrderItems":[]}'));
        $this->client($http, authScheme: '')->listItems(self::ACCOUNT, self::ORDER);

        $this->assertSame('test-token', $http->request?->getHeaderLine('Authorization'));
    }

    // ------------------------------------------------------------------ answers that are usable

    public function testAnEmptyItemListIsReturnedAsAnEmptyList(): void
    {
        $result = $this->client($this->http(new Response(200, [], '{"OrderItems":[]}')))
            ->listItems(self::ACCOUNT, self::ORDER);

        $this->assertSame([], $result['items']);
    }

    public function testOneItemIsReturned(): void
    {
        $result = $this->client($this->http(new Response(200, [], (string) json_encode([
            'OrderItems' => [['id' => '45308981', 'name' => '[F7]Wings', 'qty' => '1']],
        ]))))->listItems(self::ACCOUNT, self::ORDER);

        $this->assertCount(1, $result['items']);
        $this->assertSame('[F7]Wings', $result['items'][0]['name']);
    }

    public function testManyItemsAreReturnedInOrder(): void
    {
        $result = $this->client($this->http(new Response(200, [], (string) json_encode([
            'OrderItems' => [
                ['id' => '1', 'name' => 'First'],
                ['id' => '2', 'name' => 'Second'],
                ['id' => '3', 'name' => 'Third'],
            ],
        ]))))->listItems(self::ACCOUNT, self::ORDER);

        $this->assertCount(3, $result['items']);
        $this->assertSame(['First', 'Second', 'Third'], [
            $result['items'][0]['name'],
            $result['items'][1]['name'],
            $result['items'][2]['name'],
        ]);
    }

    /** Card data must never reach storage, even from a training endpoint. */
    public function testPaymentSecretsAreStrippedFromTheStoredEnvelope(): void
    {
        $result = $this->client($this->http(new Response(200, [], (string) json_encode([
            'OrderItems' => [[
                'id' => '1',
                'name' => 'Wings',
                'cvv' => '123',
                'card_num' => '4111111111111111',
                'data' => '{"cc":{"cvv":"999","exp_date":"12/29"},"keep":"yes"}',
            ]],
        ]))))->listItems(self::ACCOUNT, self::ORDER);

        // The envelope.
        $this->assertStringNotContainsString('cvv', $result['body']);
        $this->assertStringNotContainsString('4111111111111111', $result['body']);
        $this->assertStringNotContainsString('exp_date', $result['body']);
        $this->assertStringContainsString('keep', $result['body']);

        // AND the items handed on for mapping — `data` is stored verbatim in a column, so a secret
        // surviving here would be written to the database even though the envelope looked clean.
        $data = (string) ($result['items'][0]['data'] ?? '');
        $this->assertStringNotContainsString('cvv', $data);
        $this->assertStringNotContainsString('exp_date', $data);
        $this->assertStringContainsString('keep', $data);
        $this->assertArrayNotHasKey('cvv', $result['items'][0]);
        $this->assertArrayNotHasKey('card_num', $result['items'][0]);
    }

    // ------------------------------------------------------------------ answers that must NOT look empty

    /**
     * @dataProvider unusableBodies
     */
    public function testAnUnusableBodyIsRefusedRatherThanReadAsEmpty(string $body, string $expect): void
    {
        try {
            $this->client($this->http(new Response(200, [], $body)))->listItems(self::ACCOUNT, self::ORDER);
            $this->fail('An unusable body must not be read as an empty order.');
        } catch (OrderItemDataFailed $e) {
            $this->assertStringContainsString($expect, $e->getMessage());
            // Every one of these must promise the caller that nothing was destroyed.
            $this->assertStringContainsString('already stored were kept', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableBodies(): iterable
    {
        yield 'empty body' => ['', 'the response was empty'];
        yield 'not JSON' => ['<html>502 Bad Gateway</html>', 'not valid JSON'];
        yield 'truncated' => ['{"OrderItems":[{"id":"1","na', 'not valid JSON'];
        yield 'no OrderItems key' => ['{}', 'no "OrderItems" key'];
        yield 'OrderItems null' => ['{"OrderItems":null}', 'was not a list'];
        yield 'OrderItems a string' => ['{"OrderItems":"invalid"}', 'was not a list'];
        yield 'an entry is not an object' => ['{"OrderItems":["nope"]}', 'was not an object'];
    }

    /**
     * A partial answer is the most dangerous shape of all.
     *
     * Treating page one as the whole order would delete every line after it. The provider has
     * documented no paging protocol, so any hint of one is refused rather than guessed at.
     *
     * @dataProvider partialBodies
     */
    public function testAPartialAnswerIsRefused(string $body): void
    {
        $this->expectException(OrderItemDataFailed::class);

        $this->client($this->http(new Response(200, [], $body)))->listItems(self::ACCOUNT, self::ORDER);
    }

    /** @return iterable<string, array{string}> */
    public static function partialBodies(): iterable
    {
        yield 'next' => ['{"OrderItems":[{"id":"1"}],"next":"https://x/2"}'];
        yield 'cursor' => ['{"OrderItems":[{"id":"1"}],"cursor":"abc"}'];
        yield 'has_more' => ['{"OrderItems":[{"id":"1"}],"has_more":true}'];
        yield 'next_page' => ['{"OrderItems":[{"id":"1"}],"next_page":2}'];
        yield 'total exceeds returned' => ['{"OrderItems":[{"id":"1"}],"total":9}'];
    }

    /** A null or false marker is the provider saying "there is no more", which is fine. */
    public function testAFalseContinuationMarkerIsNotTreatedAsPartial(): void
    {
        $result = $this->client($this->http(new Response(200, [], '{"OrderItems":[{"id":"1"}],"has_more":false,"next":null}')))
            ->listItems(self::ACCOUNT, self::ORDER);

        $this->assertCount(1, $result['items']);
    }

    // ------------------------------------------------------------------ transport and status

    /**
     * @dataProvider refusals
     */
    public function testEachRefusalIsExplainedWithoutLeakingTheBody(int $status, string $expect): void
    {
        try {
            $this->client($this->http(new Response($status, [], 'Bearer leaked-token-in-body')))
                ->listItems(self::ACCOUNT, self::ORDER);
            $this->fail('A non-2xx must be refused.');
        } catch (OrderItemDataFailed $e) {
            $this->assertStringContainsString($expect, $e->getMessage());
            $this->assertStringNotContainsString('leaked-token-in-body', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function refusals(): iterable
    {
        yield '401' => [401, 'rejected this server\'s credentials'];
        yield '403' => [403, 'rejected this server\'s credentials'];
        yield '404' => [404, 'does not know this order'];
        yield '429' => [429, 'rate limiting'];
        yield '500' => [500, 'unavailable'];
        yield '418' => [418, 'refused the request'];
    }

    public function testATransportFailureNeverCarriesTheUri(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connect to https://secret.host/path?token=abc failed') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->client($http)->listItems(self::ACCOUNT, self::ORDER);
            $this->fail('A transport failure must be refused.');
        } catch (OrderItemDataFailed $e) {
            $this->assertStringNotContainsString('secret.host', $e->getMessage());
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }
    }

    /**
     * A timeout is a refusal, never an empty order.
     *
     * The distinction this whole feature rests on, at the transport layer: a request that never
     * finished tells us nothing about the order's lines, and reading it as "no items" would store an
     * authoritative empty set for an order that may be full. Guzzle surfaces both connect and read
     * timeouts as a `ClientExceptionInterface`, which is the shape simulated here; the real timeouts
     * come from `ORDER58_ITEM_API_CONNECT_TIMEOUT_SECONDS` and `ORDER58_ITEM_API_TIMEOUT_SECONDS`,
     * applied to this client's own Guzzle instance.
     */
    public function testATimeoutIsRefusedRatherThanReadAsAnEmptyOrder(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('cURL error 28: Operation timed out after 25001 milliseconds') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->client($http)->listItems(self::ACCOUNT, self::ORDER);
            $this->fail('A timeout must be refused.');
        } catch (OrderItemDataFailed $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
            // The caller must be able to tell this apart from `OrderItems: []`.
            $this->assertStringNotContainsString('empty', $e->getMessage());
        }
    }

    public function testAnOversizedBodyIsAbandoned(): void
    {
        $huge = '{"OrderItems":[' . str_repeat('{"id":"1","name":"' . str_repeat('x', 1000) . '"},', 2000) . '{}]}';

        $this->expectException(OrderItemDataFailed::class);
        $this->expectExceptionMessageMatches('/larger than this server will read/');

        $this->client($this->http(new Response(200, [], $huge)), maxBytes: 65536)
            ->listItems(self::ACCOUNT, self::ORDER);
    }

    // ------------------------------------------------------------------ helpers

    private function http(ResponseInterface $response): RecordingHttpClient
    {
        return new RecordingHttpClient($response);
    }

    private function client(
        ClientInterface $http,
        string $url = 'https://example.invalid/api/order-item-data/list',
        string $token = 'test-token',
        string $authScheme = 'Bearer',
        bool $enabled = true,
        int $maxBytes = 4194304,
    ): HttpOrderItemClient {
        $psr7 = new HttpFactory();

        return new HttpOrderItemClient($http, $psr7, $psr7, new OrderItemSettings(
            url: $url,
            token: $token,
            authScheme: $authScheme,
            connectTimeoutSeconds: 5,
            timeoutSeconds: 25,
            maxResponseBytes: $maxBytes,
            enabled: $enabled,
        ));
    }
}
