<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Orders;

use App\Order58\Client\Orders\HttpOrderDataClient;
use App\Order58\Client\Orders\OrderDataFailed;
use App\Order58\Client\Orders\OrderDataSettings;
use Codeception\Test\Unit;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

use function json_encode;
use function str_repeat;

/**
 * The Orders API client. **No test here reaches the network** — every response is a canned PSR-7 one.
 *
 * What matters most is the two things that would otherwise only be discovered in production: that an
 * oversized body is refused rather than decoded, and that no failure path carries a credential or a
 * provider's own words into a message rendered on an admin page.
 */
final class OrderDataClientTest extends Unit
{
    private const TOKEN = 'test-token-not-a-real-credential';

    public function testTheOrdersArrayIsReturned(): void
    {
        $orders = $this->client($this->ok([['id' => '1'], ['id' => '2']]))
            ->listOrders(1141, '2026-09-30', '2026-10-01');

        $this->assertCount(2, $orders);
        $this->assertSame('1', $orders[0]['id']);
    }

    public function testTheRequestCarriesTheAccountAndDatesAsJson(): void
    {
        $stack = HandlerStack::create(new MockHandler([$this->ok([])]));

        $sent = null;
        // A regular closure, not an arrow function: arrow functions capture by value, so a `&$sent`
        // inside one never reaches this scope and the assertion below silently sees null.
        $stack->push(static function (callable $next) use (&$sent) {
            return static function ($request, array $options) use ($next, &$sent) {
                $sent = $request;

                return $next($request, $options);
            };
        });

        $this->clientWith($stack)->listOrders(1141, '2026-09-30', '2026-10-01');

        $this->assertNotNull($sent);
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame(
            ['account_id' => '1141', 'date_from' => '2026-09-30', 'date_to' => '2026-10-01'],
            json_decode((string) $sent->getBody(), true),
        );
        $this->assertSame('Bearer ' . self::TOKEN, $sent->getHeaderLine('Authorization'));
    }

    /**
     * A one-day sync sends the same date twice, exactly as typed.
     *
     * The guard against a tempting "helpful" fix: widening `date_to` by a day to make a single date
     * behave would mirror a day of orders nobody asked for, and the operator would have no way to tell.
     */
    public function testASingleDaySendsTheSameDateForBothBounds(): void
    {
        $stack = HandlerStack::create(new MockHandler([$this->ok([])]));

        $sent = null;
        $stack->push(static function (callable $next) use (&$sent) {
            return static function ($request, array $options) use ($next, &$sent) {
                $sent = $request;

                return $next($request, $options);
            };
        });

        $this->clientWith($stack)->listOrders(1141, '2026-10-02', '2026-10-02');

        $this->assertSame(
            ['account_id' => '1141', 'date_from' => '2026-10-02', 'date_to' => '2026-10-02'],
            json_decode((string) $sent->getBody(), true),
        );
    }

    public function testAnEmptyOrdersArrayIsNotAnError(): void
    {
        $this->assertSame([], $this->client($this->ok([]))->listOrders(1141, '2026-09-30', '2026-10-01'));
    }

    /**
     * **The memory guard.** An oversized body is abandoned, never decoded.
     *
     * The ceiling here is tiny so the test stays fast; the production value is measured against a real
     * order (~5.3 KB encoded, decoding to at most ~4x that) against a 128M limit.
     */
    public function testAnOversizedResponseIsRefusedBeforeDecoding(): void
    {
        $huge = json_encode(['Orders' => [['id' => '1', 'pad' => str_repeat('x', 200000)]]]);

        $this->expectException(OrderDataFailed::class);
        $this->expectExceptionMessageMatches('/larger than this server will read/');

        $this->client(new GuzzleResponse(200, [], (string) $huge), maxBytes: 4096)
            ->listOrders(1141, '2026-09-30', '2026-10-01');
    }

    /**
     * @dataProvider refusals
     */
    public function testEachRefusalIsExplainedWithoutLeakingTheBody(int $status, string $expect): void
    {
        try {
            $this->client(new GuzzleResponse($status, [], 'SECRET-PROVIDER-BODY'))
                ->listOrders(1141, '2026-09-30', '2026-10-01');
            $this->fail('Expected a failure for HTTP ' . $status);
        } catch (OrderDataFailed $e) {
            $this->assertStringContainsString($expect, $e->getMessage());
            // A provider's error page is written by somebody else and this message is rendered on an
            // admin screen; it must never carry the body through.
            $this->assertStringNotContainsString('SECRET-PROVIDER-BODY', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusals(): array
    {
        return [
            '401' => [401, 'credentials'],
            '403' => [403, 'credentials'],
            '429' => [429, 'rate limiting'],
            '500' => [500, 'unavailable'],
            '503' => [503, 'unavailable'],
            '400' => [400, 'refused the request'],
        ];
    }

    public function testATransportFailureIsReportedWithoutTheUrl(): void
    {
        $handler = new MockHandler([
            new ConnectException('cURL error 7', new GuzzleRequest('POST', 'https://example.test/secret-path')),
        ]);

        try {
            $this->clientWith(HandlerStack::create($handler))->listOrders(1141, '2026-09-30', '2026-10-01');
            $this->fail('Expected a failure');
        } catch (OrderDataFailed $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
            $this->assertStringNotContainsString('secret-path', $e->getMessage());
        }
    }

    /**
     * @dataProvider malformed
     */
    public function testAnUnusableBodyIsRefused(string $body): void
    {
        $this->expectException(OrderDataFailed::class);

        $this->client(new GuzzleResponse(200, [], $body))->listOrders(1141, '2026-09-30', '2026-10-01');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'not json' => ['{not json'],
            'no Orders key' => ['{"success":true}'],
            'Orders not a list' => ['{"Orders":"nope"}'],
        ];
    }

    /** A non-object row is dropped rather than costing the whole response. */
    public function testANonObjectRowIsSkipped(): void
    {
        $body = (string) json_encode(['Orders' => [['id' => '1'], 'garbage', ['id' => '2']]]);

        $orders = $this->client(new GuzzleResponse(200, [], $body))->listOrders(1141, '2026-09-30', '2026-10-01');

        $this->assertCount(2, $orders);
    }

    /** Nothing is sent at all when the endpoint was never configured. */
    public function testAnUnconfiguredClientNamesTheMissingSettings(): void
    {
        $client = new HttpOrderDataClient(
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$this->ok([])]))]),
            new HttpFactory(),
            new HttpFactory(),
            new OrderDataSettings('', '', 5, 25, 1048576),
        );

        try {
            $client->listOrders(1141, '2026-09-30', '2026-10-01');
            $this->fail('Expected a failure');
        } catch (OrderDataFailed $e) {
            $this->assertStringContainsString('ORDER58_ORDERS_API_URL', $e->getMessage());
            $this->assertStringContainsString('ORDER58_ORDERS_API_TOKEN', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param list<array<string, mixed>> $orders
     */
    private function ok(array $orders): GuzzleResponse
    {
        return new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'success' => true,
            'Orders' => $orders,
        ]));
    }

    private function client(GuzzleResponse $response, int $maxBytes = 1048576): HttpOrderDataClient
    {
        return $this->clientWith(HandlerStack::create(new MockHandler([$response])), $maxBytes);
    }

    private function clientWith(HandlerStack $stack, int $maxBytes = 1048576): HttpOrderDataClient
    {
        $psr7 = new HttpFactory();

        return new HttpOrderDataClient(
            new GuzzleClient(['handler' => $stack, 'http_errors' => false]),
            $psr7,
            $psr7,
            new OrderDataSettings('https://orders.test/api/order-data/list', self::TOKEN, 5, 25, $maxBytes),
        );
    }
}
