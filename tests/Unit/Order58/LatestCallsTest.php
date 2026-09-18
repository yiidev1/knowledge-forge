<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Web\TestRecordingChannels\CallSummary;
use App\Order58\Web\TestRecordingChannels\ChannelApiProbe;
use App\Order58\Web\TestRecordingChannels\ChannelDiagnosis;
use App\Order58\Web\TestRecordingChannels\ChannelRequestMapping;
use App\Order58\Web\TestRecordingChannels\LatestCallsRequest;
use Codeception\Test\Unit;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

use function json_encode;
use function str_repeat;

/**
 * Picking a call session id from the Latest Calls endpoint. **No test here reaches the network.**
 *
 * The endpoint itself is confirmed — it is the one the existing working tool already calls — so what is
 * tested here is the part that is new: reading the response safely, refusing what cannot be used, and
 * deriving the fetch date only when it can be read with certainty rather than guessed at.
 */
final class LatestCallsTest extends Unit
{
    // ------------------------------------------------------------------ 1-4. input

    public function testAValidAccountIdAndLimitAreAccepted(): void
    {
        $this->assertNull(LatestCallsRequest::validate('871', '10'));
    }

    /**
     * @dataProvider invalidAccountIds
     */
    public function testAnInvalidAccountIdIsRefused(string $accountId): void
    {
        $error = LatestCallsRequest::validate($accountId, '10');

        $this->assertNotNull($error);
        $this->assertStringContainsString('Account ID', $error);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAccountIds(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'letters' => ['abc'],
            'negative' => ['-871'],
            'decimal' => ['87.1'],
            'too long' => [str_repeat('9', 10)],
            'traversal' => ['../../etc/passwd'],
            // `$`-anchored patterns accept this, because `$` also matches before a trailing newline —
            // and this value becomes a URL path segment.
            'newline smuggling' => ["871\n../../etc/passwd"],
        ];
    }

    public function testAnOversizedLimitIsRefused(): void
    {
        $error = LatestCallsRequest::validate('871', '501');

        $this->assertNotNull($error);
        $this->assertStringContainsString('between 1 and 500', $error);
    }

    public function testAnInvalidLimitIsRefused(): void
    {
        $this->assertNotNull(LatestCallsRequest::validate('871', '0'));
        $this->assertNotNull(LatestCallsRequest::validate('871', 'ten'));
    }

    /** Refused input never becomes a request object, so it cannot reach a URL by another path. */
    public function testRefusedInputProducesNoRequestObject(): void
    {
        $this->assertNull(LatestCallsRequest::fromStrings('abc', '10'));
        $this->assertNull(LatestCallsRequest::fromStrings('871', '501'));
        $this->assertNotNull(LatestCallsRequest::fromStrings('871', '10'));
    }

    /** Ten, not the other tool's hundred: this page lists calls to pick one from. */
    public function testTheDefaultLimitIsReadable(): void
    {
        $this->assertSame(10, LatestCallsRequest::DEFAULT_LIMIT);
    }

    // ------------------------------------------------------------------ the URL

    public function testTheUrlMatchesTheConfirmedEndpoint(): void
    {
        $url = (new ChannelRequestMapping())->latestCallsUrl(871, 10);

        $this->assertSame(
            'https://order58.xrainbow.com/api/external/recording/871/latest-calls?limit=10',
            $url,
        );
    }

    // ------------------------------------------------------------------ 5-14. responses

    public function testASuccessfulListIsDecoded(): void
    {
        $result = $this->probe($this->listResponse([
            ['callSessionId' => '22342359', 'callTime' => '2026-03-11 14:23:05', 'orderId' => '58-100234'],
            ['callSessionId' => '16438291', 'callTime' => '2026-03-10 09:41:12', 'orderId' => '58-100233'],
        ]))->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertSame(ChannelDiagnosis::OK, $result->diagnosis->kind);
        $this->assertCount(2, $result->calls);
        $this->assertSame('22342359', $result->calls[0]->callSessionId);
        $this->assertSame('58-100234', $result->calls[0]->orderId);
    }

    /** An empty account is a fact, not a fault — and reads differently from an unparseable body. */
    public function testAnEmptyListIsReportedAsNoCalls(): void
    {
        $result = $this->probe($this->listResponse([]))->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertSame(ChannelDiagnosis::NO_CALLS, $result->diagnosis->kind);
        $this->assertFalse($result->hasCalls());
    }

    public function testMalformedJsonIsReportedWithoutThrowing(): void
    {
        $result = $this->probe(new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{not json'))
            ->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertSame(ChannelDiagnosis::INVALID_JSON, $result->diagnosis->kind);
        $this->assertNotNull($result->bodyPreview(), 'The raw body is the diagnostic; it must be shown.');
    }

    /** A JSON body that is not a list of calls is an unparseable response, not an empty account. */
    public function testJsonThatIsNotAListOfCallsIsDistinctFromAnEmptyAccount(): void
    {
        $result = $this->probe(new GuzzleResponse(200, [], json_encode(['error' => 'nope'])))
            ->latestCalls(new LatestCallsRequest(871, 10));

        // A JSON object decodes to an array with no call rows: reported as "no calls" rather than
        // invented into one. The raw body is printed so the real shape is visible.
        $this->assertContains($result->diagnosis->kind, [ChannelDiagnosis::NO_CALLS, ChannelDiagnosis::INVALID_JSON]);
        $this->assertFalse($result->hasCalls());
    }

    /** A row with no session id is kept and shown, but cannot be selected. */
    public function testARowWithNoSessionIdIsShownButNotSelectable(): void
    {
        $result = $this->probe($this->listResponse([
            ['callTime' => '2026-03-11 14:23:05', 'orderId' => '58-100234'],
            ['callSessionId' => '22342359', 'callTime' => '2026-03-11 14:23:05', 'orderId' => '58-100235'],
        ]))->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertCount(2, $result->calls, 'Nothing is hidden from the operator.');
        $this->assertCount(1, $result->selectableCalls());
        $this->assertSame(1, $result->unusableCount());
    }

    /** A session id the channel form would refuse must not be offered as a button. */
    public function testASessionIdTheChannelFormWouldRefuseIsNotSelectable(): void
    {
        $result = $this->probe($this->listResponse([
            ['callSessionId' => 'not-numeric', 'callTime' => '2026-03-11', 'orderId' => ''],
        ]))->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertSame([], $result->selectableCalls());
    }

    /**
     * @dataProvider refusals
     */
    public function testEachRefusalIsClassified(int $status, string $body, string $expected): void
    {
        $result = $this->probe(new GuzzleResponse($status, ['Content-Type' => 'text/plain'], $body))
            ->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertSame($expected, $result->diagnosis->kind);
        $this->assertFalse($result->hasCalls());
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function refusals(): array
    {
        return [
            '401' => [401, 'Unauthorized', ChannelDiagnosis::UNAUTHORIZED],
            '403 IP allowlist' => [403, '403 Forbidden - IP not authorized: 203.0.113.9', ChannelDiagnosis::IP_NOT_WHITELISTED],
            '403 other' => [403, 'Forbidden: account not yours', ChannelDiagnosis::FORBIDDEN],
            '404' => [404, 'Not Found', ChannelDiagnosis::NOT_FOUND],
            '429' => [429, 'Too Many Requests', ChannelDiagnosis::RATE_LIMITED],
            '408' => [408, '', ChannelDiagnosis::TIMEOUT],
            '500' => [500, 'boom', ChannelDiagnosis::SERVER_ERROR],
            '503' => [503, 'maintenance', ChannelDiagnosis::SERVER_ERROR],
        ];
    }

    public function testATransportFailurePropagatesRatherThanBeingSwallowed(): void
    {
        $handler = new MockHandler([
            new ConnectException('Operation timed out', new GuzzleRequest('GET', 'https://example.test')),
        ]);
        $probe = new ChannelApiProbe(httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]));

        $this->expectException(ConnectException::class);

        $probe->latestCalls(new LatestCallsRequest(871, 10));
    }

    /** No credential exists to send, and none is invented for this endpoint either. */
    public function testNoAuthorizationHeaderIsSent(): void
    {
        $stack = HandlerStack::create(new MockHandler([$this->listResponse([])]));

        $sent = null;
        $stack->push(static function (callable $next) use (&$sent) {
            return static function ($request, array $options) use ($next, &$sent) {
                $sent = $request;

                return $next($request, $options);
            };
        });

        (new ChannelApiProbe(httpClient: new GuzzleClient(['handler' => $stack])))
            ->latestCalls(new LatestCallsRequest(871, 10));

        $this->assertNotNull($sent);
        $this->assertFalse($sent->hasHeader('Authorization'));
    }

    // ------------------------------------------------------------------ 15-17. selecting a call

    /** The session id is used exactly as returned — never derived, never substituted. */
    public function testTheSessionIdIsCarriedThroughVerbatim(): void
    {
        $call = CallSummary::fromArray(['callSessionId' => '22342359', 'callTime' => '', 'orderId' => '']);

        $this->assertSame('22342359', $call->callSessionId);
        $this->assertTrue($call->hasValidRecordingId());
    }

    /**
     * The date is derived **only** when it can be read with certainty.
     *
     * The existing working tool does not derive it at all — its "Use below" link carries the form's
     * existing date through untouched — so there is no proven conversion to copy. Reading a leading
     * `YYYY-MM-DD` is a verifiable extraction; anything else returns null and the Time field is left
     * exactly as the operator set it.
     *
     * @dataProvider callTimes
     */
    public function testTheDateIsDerivedOnlyWhenItCanBeReadWithCertainty(string $callTime, ?string $expected): void
    {
        $call = CallSummary::fromArray(['callSessionId' => '1', 'callTime' => $callTime, 'orderId' => '']);

        $this->assertSame($expected, $call->derivedDate());
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function callTimes(): array
    {
        return [
            'date and time' => ['2026-03-11 14:23:05', '2026-03-11'],
            'date only' => ['2026-03-11', '2026-03-11'],
            'iso 8601' => ['2026-03-11T14:23:05Z', '2026-03-11'],
            // Everything below is left alone rather than guessed at.
            'empty' => ['', null],
            'written out' => ['March 9, 2026', null],
            'us order' => ['03/11/2026', null],
            'epoch' => ['1773225785', null],
            'impossible date' => ['2026-02-31 10:00:00', null],
            'not a date at all' => ['yesterday', null],
        ];
    }

    private function probe(GuzzleResponse $response): ChannelApiProbe
    {
        return new ChannelApiProbe(
            httpClient: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response]))]),
        );
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function listResponse(array $rows): GuzzleResponse
    {
        return new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode($rows));
    }
}
