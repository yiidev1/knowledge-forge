<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\FixtureRecordingSource;
use App\Integration\Order58Recording\RecordingUrlPolicy;
use App\Order58\Client\Receipt\ReceiptClientInterface;
use App\Order58\Client\Receipt\ReceiptDelivery;
use App\Order58\Client\Receipt\ReceiptFailed;
use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Application\RecordingStream\RecordingStreamGate;
use App\Order58\Application\RecordingStream\RecordingStreamSettings;
use App\Order58\Web\RecentCalls\AudioAction;
use App\Order58\Web\RecentCalls\ListAction;
use App\Order58\Web\RecentCalls\ReceiptAction;
use App\Order58\Web\RecentCalls\RecentCallsLoader;
use App\Order58\Web\RecentCalls\RecentCallsReceipts;
use App\Order58\Web\RecentCalls\RecentCallsSettings;
use App\Order58\Web\RecordingAudio\RecordingResponder;
use App\Tests\Support\Fake\Shared\RecordingSession;
use Codeception\Test\Unit;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Yiisoft\Router\UrlGeneratorInterface;

use function array_column;
use function http_build_query;
use function json_decode;
use function pack;
use function str_repeat;
use function sys_get_temp_dir;

/**
 * The Recent Calls page: two stores only, one metadata request per Load Calls, three recordings per call
 * fetched only on demand through the store page's own streamer, and a receipt for every listed order.
 *
 * The provider is a Guzzle mock, so every request this sends is in `$history` — "only the list was
 * requested" and "the caller channel's address carries the suffix" are assertions, not hopes.
 */
final class RecentCallsTest extends Unit
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    private RecordingSession $session;

    protected function _before(): void
    {
        $this->history = [];
        $this->session = new RecordingSession();
    }

    // ---------------------------------------------------------------- the store list

    public function testOnlyTheTwoConfiguredStoresAreOffered(): void
    {
        self::assertSame([1731 => 'Best Chinese Cuisine II', 91 => 'GREAT WALL'], RecentCallsSettings::options());
        self::assertSame(1731, RecentCallsSettings::DEFAULT_STORE);
        self::assertSame(25, RecentCallsSettings::LIMIT);
    }

    public function testAnyOtherAccountIsRefused(): void
    {
        self::assertSame(1731, RecentCallsSettings::find('1731'));
        self::assertSame(91, RecentCallsSettings::find('91'));
        self::assertSame(91, RecentCallsSettings::find(91));

        foreach (['92', '871', '0', '', '-91', '91 ', "91\n", '1731abc', '0x6c3', '1e3'] as $raw) {
            self::assertNull(RecentCallsSettings::find($raw), 'accepted ' . $raw);
        }

        self::assertNull(RecentCallsSettings::find(['91']));
        self::assertNull(RecentCallsSettings::find(null));
    }

    // ---------------------------------------------------------------- the address policy

    /** The streamer may fetch one channel of a session; storage still accepts plain sessions only. */
    public function testAChannelAddressIsFetchableButNotStorable(): void
    {
        $base = 'https://order58.xrainbow.com/api/external/recording/fetch/';
        $query = '?time=2026-11-25&company=SWCC&name=23902889-caller';

        self::assertTrue(RecordingUrlPolicy::acceptsForFetch($base . '23902889' . $query));
        self::assertTrue(RecordingUrlPolicy::acceptsForFetch($base . '23902889-caller' . $query));
        self::assertTrue(RecordingUrlPolicy::acceptsForFetch($base . '23902889-callee' . $query));
        self::assertFalse(RecordingUrlPolicy::accepts($base . '23902889-caller' . $query), 'storage rule unchanged');

        foreach ([
            $base . '23902889-mixed',
            $base . '23902889-agent',
            $base . '23902889-caller-callee',
            $base . '23902889-caller/x',
            $base . 'abc-caller',
            $base . '-caller',
            'https://example.com/api/external/recording/fetch/23902889-caller',
            'http://order58.xrainbow.com/api/external/recording/fetch/23902889-caller',
            'https://order58.xrainbow.com:8443/api/external/recording/fetch/23902889-caller',
        ] as $url) {
            self::assertFalse(RecordingUrlPolicy::acceptsForFetch($url), $url);
        }
    }

    // ---------------------------------------------------------------- loading calls

    public function testLoadCallsMakesOneListRequestAndNoAudioRequest(): void
    {
        $list = $this->loader(new Response(200, [], (string) json_encode([
            ['callSessionId' => '23905479', 'callTime' => '2026-11-25 01:57:24', 'orderId' => '17702641'],
            ['callSessionId' => '23905569', 'callTime' => '2026-11-25 01:58:59', 'orderId' => '17702731'],
        ])))->load(1731, false);

        self::assertCount(1, $this->history, 'only the list was requested');
        self::assertSame('GET', $this->history[0]['request']->getMethod());
        self::assertSame(
            'https://order58.xrainbow.com/api/external/recording/1731/latest-calls?limit=25',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertNull($list->problem);
        self::assertSame(['23905569', '23905479'], array_column($list->calls, 'callSessionId'), 'newest first');
        self::assertSame('17702731', $list->calls[0]->orderId);
        self::assertSame('2026-11-25 01:58:59', $list->calls[0]->callTime);
    }

    public function testGreatWallAsksForItsOwnAccount(): void
    {
        $this->loader(new Response(200, [], '[]'))->load(91, false);

        self::assertSame(
            'https://order58.xrainbow.com/api/external/recording/91/latest-calls?limit=25',
            (string) $this->history[0]['request']->getUri(),
        );
    }

    public function testNoCallsIsAnEmptyListNotAnError(): void
    {
        $list = $this->loader(new Response(200, [], '[]'))->load(1731, false);

        self::assertSame([], $list->calls);
        self::assertNull($list->problem);
    }

    public function testAMalformedAnswerIsAProblemNotACrash(): void
    {
        $list = $this->loader(new Response(200, [], '{"calls": oops'))->load(1731, false);

        self::assertSame([], $list->calls);
        self::assertSame(ChannelDiagnosis::INVALID_JSON, $list->problem?->kind);
    }

    public function testAnAllowlistRefusalIsNamed(): void
    {
        $list = $this->loader(new Response(403, [], 'Forbidden - IP not authorized: 1.2.3.4'))->load(1731, false);

        self::assertSame(ChannelDiagnosis::IP_NOT_WHITELISTED, $list->problem?->kind);
    }

    public function testAConnectionFailureOrTimeoutIsAProblemNotACrash(): void
    {
        $list = $this->loader(new ConnectException('Connection timed out', new Request('GET', 'x')))->load(1731, false);

        self::assertSame(ChannelDiagnosis::TIMEOUT, $list->problem?->kind);
        self::assertStringNotContainsString('xrainbow', $list->problem->headline . $list->problem->advice);
    }

    /** A time the page cannot read keeps the provider's own order rather than a guessed one. */
    public function testUnreadableTimesKeepTheProvidersOrder(): void
    {
        $calls = [
            new CallSummary('1', 'March 9, 2026', ''),
            new CallSummary('2', '2026-11-25 01:58:59', ''),
        ];

        self::assertSame($calls, RecentCallsLoader::newestFirst($calls));
    }

    // ---------------------------------------------------------------- the list endpoint

    public function testTheListCarriesEveryAddressTheRowNeeds(): void
    {
        $data = $this->list(['store' => '1731'], new Response(200, [], (string) json_encode([
            ['callSessionId' => '23905569', 'callTime' => '2026-11-25 01:58:59', 'orderId' => '17702731'],
            ['callSessionId' => '23905479', 'callTime' => '2026-11-25 01:57:24', 'orderId' => '17702641'],
            ['callSessionId' => '23905459', 'callTime' => '2026-11-25 01:55:47', 'orderId' => ''],
        ])));

        self::assertSame(1731, $data['store']);
        self::assertCount(3, $data['calls']);

        $first = $data['calls'][0];
        self::assertSame('17702731', $first['orderNo']);
        self::assertSame('23905569', $first['sessionId']);
        self::assertSame('2026-11-25 01:58:59', $first['callTime']);
        self::assertSame(['mixed', 'caller', 'callee'], array_keys($first['channels']));
        self::assertSame(
            'order58.recent-calls.audio?' . http_build_query([
                'store' => 1731, 'session' => '23905569', 'date' => '2026-11-25', 'channel' => 'caller',
            ]),
            $first['channels']['caller']['play'],
        );
        self::assertStringEndsWith('&download=1', $first['channels']['callee']['download']);
        self::assertSame('23905569-callee.wav', $first['channels']['callee']['fileName']);
        self::assertSame('23905569.wav', $first['channels']['mixed']['fileName']);

        // A receipt link for every call with an order id, synchronised here or not.
        self::assertSame('order58.recent-calls.receipt?store=1731&order=17702731', $first['receipt']);
        self::assertSame('order58.recent-calls.receipt?store=1731&order=17702641', $data['calls'][1]['receipt']);
        self::assertNull($data['calls'][2]['receipt'], 'no order at all');

        // And those orders, only, may now be opened for this store.
        $listed = new RecentCallsReceipts($this->session);
        self::assertTrue($listed->allows(1731, 17702731));
        self::assertTrue($listed->allows(1731, 17702641));
        self::assertFalse($listed->allows(91, 17702731), 'listed for 1731, not for 91');
        self::assertFalse($listed->allows(1731, 17702732), 'never listed');

        self::assertCount(1, $this->history, 'no recording was fetched');
        self::assertContains('session.close', $this->session->events, 'the session was released');
    }

    /**
     * The session is released before the provider is asked, so one slow Load Calls or Play cannot make
     * the same person's other pages wait on the session file.
     */
    public function testTheSessionIsReleasedBeforeTheProviderIsContacted(): void
    {
        // Reopened only after the provider has answered, to remember which receipts may be opened.
        $this->list(['store' => '1731'], new Response(200, [], '[]'));
        self::assertSame(['session.close', 'provider.request', 'session.open'], $this->session->events, 'Load Calls');

        $this->session->events = [];
        $this->audio(['channel' => 'caller'], new Response(200, [], $this->wav()));
        self::assertSame(['session.close', 'provider.request'], $this->session->events, 'Play');
    }

    public function testACallWithoutAReadableDateGetsNoAudioButStillLists(): void
    {
        $data = $this->list(['store' => '91'], new Response(200, [], (string) json_encode([
            ['callSessionId' => '23905569', 'callTime' => 'yesterday', 'orderId' => '1'],
        ])));

        self::assertSame(
            ['mixed' => null, 'caller' => null, 'callee' => null],
            $data['calls'][0]['channels'],
        );
    }

    public function testAnUnlistedStoreIsRefusedBeforeAnyRequest(): void
    {
        $response = $this->listResponse(['store' => '871']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->history);
    }

    public function testAnUpstreamFailureIsA502WithOurOwnWords(): void
    {
        $response = $this->listResponse(['store' => '1731'], new Response(500, [], 'stack trace from the provider'));
        $body = (string) $response->getBody();

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('stack trace', $body);
        self::assertStringNotContainsString('xrainbow', $body);
    }

    // ---------------------------------------------------------------- the audio endpoint

    /** @return iterable<string, array{string, string, string}> */
    public static function channels(): iterable
    {
        yield 'mixed' => ['mixed', '23902889', '23902889.wav'];
        yield 'caller' => ['caller', '23902889-caller', '23902889-caller.wav'];
        yield 'callee' => ['callee', '23902889-callee', '23902889-callee.wav'];
    }

    /** @dataProvider channels */
    public function testEachChannelIsFetchedFromItsOwnAddress(string $channel, string $segment, string $file): void
    {
        $response = $this->audio(['channel' => $channel, 'download' => '1'], new Response(200, [], $this->wav()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="' . $file . '"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame($this->wav(), (string) $response->getBody(), 'the original bytes, unchanged');
        self::assertCount(1, $this->history);
        self::assertSame(
            'https://order58.xrainbow.com/api/external/recording/fetch/' . $segment
                . '?time=2026-11-25&company=SWCC&name=' . $segment,
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertContains('session.close', $this->session->events);
    }

    public function testPlayIsInlineAndSeekingIsAnswered(): void
    {
        $response = $this->audio(['channel' => 'caller'], new Response(200, [], $this->wav()));
        self::assertSame('inline; filename="23902889-caller.wav"', $response->getHeaderLine('Content-Disposition'));

        $ranged = $this->audio(['channel' => 'caller'], new Response(200, [], $this->wav()), 'bytes=0-9');
        self::assertSame(206, $ranged->getStatusCode());
        self::assertSame('10', $ranged->getHeaderLine('Content-Length'));
    }

    public function testAMissingRecordingIsA404NotAnEmptyFile(): void
    {
        $response = $this->audio(['channel' => 'callee'], new Response(404));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testAnHtmlBodyIsNeverHandedOverAsAudio(): void
    {
        $response = $this->audio(['channel' => 'mixed'], new Response(200, [], '<html>error</html>'));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testTheAllowlistRefusalIsNamed(): void
    {
        $response = $this->audio(['channel' => 'mixed'], new Response(403, [], 'Forbidden - IP not authorized'));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('allowlist', (string) $response->getBody());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function refusedAudio(): iterable
    {
        yield 'another store' => [['store' => '871']];
        yield 'an unknown channel' => [['channel' => 'agent']];
        yield 'a non-numeric session' => [['session' => '../../etc']];
        yield 'an impossible date' => [['date' => '2026-02-31']];
        yield 'no date' => [['date' => '']];
    }

    /**
     * @dataProvider refusedAudio
     *
     * @param array<string, string> $override
     */
    public function testBadInputIsRefusedBeforeAnyRequest(array $override): void
    {
        $response = $this->audio($override);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->history, 'nothing was requested');
    }

    // ---------------------------------------------------------------- the receipt

    /** Invalid or missing order ids get no link, and the call still lists. */
    public function testOnlyANumericOrderIdGetsAReceiptLink(): void
    {
        foreach (['17702731' => 17702731, '' => null, '58-100234' => null, '0' => null, '0123' => null, 'abc' => null, '1e5' => null] as $raw => $expected) {
            self::assertSame($expected, RecentCallsReceipts::orderIdOf((string) $raw), 'order id ' . $raw);
        }
    }

    /** A listed order's receipt is fetched with exactly the listed pair and shown on the existing page. */
    public function testAListedOrdersReceiptOpens(): void
    {
        (new RecentCallsReceipts($this->session))->remember(1731, [17702731]);
        $client = new RecordingReceipts();
        $this->session->events = [];

        $response = $this->receipt($client, ['store' => '1731', 'order' => '17702731']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[1731, 17702731]], $client->asked);
        self::assertStringContainsString('data:image/png;base64,', (string) $response->getBody());
        self::assertSame('session.close', $this->session->events[0] ?? null, 'released before the provider is asked');
    }

    /** @return iterable<string, array{array<string, string>, int}> */
    public static function refusedReceipts(): iterable
    {
        yield 'never listed' => [['store' => '1731', 'order' => '17702732'], 404];
        yield 'listed for another store' => [['store' => '91', 'order' => '17702731'], 404];
        yield 'a store this page does not offer' => [['store' => '871', 'order' => '17702731'], 400];
        yield 'no order id' => [['store' => '1731', 'order' => ''], 400];
        yield 'a non-numeric order id' => [['store' => '1731', 'order' => '58-100234'], 400];
    }

    /**
     * Anything not listed for that store is refused before the provider is asked: the provider itself
     * does not check that an order belongs to the account it is asked about.
     *
     * @dataProvider refusedReceipts
     *
     * @param array<string, string> $query
     */
    public function testAnythingNotListedIsRefusedWithoutAskingTheProvider(array $query, int $status): void
    {
        (new RecentCallsReceipts($this->session))->remember(1731, [17702731]);
        $client = new RecordingReceipts();

        $response = $this->receipt($client, $query);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame([], $client->asked);
    }

    /** A provider failure is the existing page's readable failure, not a blank tab. */
    public function testAProviderFailureIsShownInWords(): void
    {
        (new RecentCallsReceipts($this->session))->remember(1731, [17702731]);
        $client = new RecordingReceipts(ReceiptFailed::notAvailable());

        $response = $this->receipt($client, ['store' => '1731', 'order' => '17702731']);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('The receipt could not be loaded', (string) $response->getBody());
    }

    // ---------------------------------------------------------------- helpers

    private function client(Response|ConnectException ...$answers): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $stack->push(Middleware::tap(fn() => $this->session->record('provider.request')));

        return new Client(['handler' => $stack]);
    }

    private function loader(Response|ConnectException ...$answers): RecentCallsLoader
    {
        return new RecentCallsLoader(new ChannelApiProbe($this->client(...$answers)), new FixtureRecordingSource());
    }

    /** @param array<string, string> $query */
    private function listResponse(array $query, Response ...$answers): ResponseInterface
    {
        $urls = new class implements UrlGeneratorInterface {
            public function generate(string $name, array $arguments = [], array $queryParameters = [], ?string $hash = null): string
            {
                $path = $name . ($arguments === [] ? '' : '/' . implode('/', $arguments));

                return $path . ($queryParameters === [] ? '' : '?' . http_build_query($queryParameters));
            }

            public function generateAbsolute(string $name, array $arguments = [], array $queryParameters = [], ?string $hash = null, ?string $scheme = null, ?string $host = null): string
            {
                return $this->generate($name, $arguments, $queryParameters, $hash);
            }

            public function generateFromCurrent(array $replacedArguments, array $queryParameters = [], ?string $hash = null, ?string $fallbackRouteName = null): string
            {
                return '';
            }

            public function getUriPrefix(): string
            {
                return '';
            }

            public function setUriPrefix(string $name): void {}

            public function setDefaultArgument(string $name, \Stringable|string|bool|int|float|null $value): void {}
        };

        $action = new ListAction($this->loader(...$answers), new HttpFactory(), $urls, $this->session, new RecentCallsReceipts($this->session));

        return $action((new ServerRequest('GET', '/admin/order58/recent-calls/list'))->withQueryParams($query));
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function list(array $query, Response ...$answers): array
    {
        $response = $this->listResponse($query, ...$answers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $override */
    private function audio(array $override, ?Response $answer = null, ?string $range = null): ResponseInterface
    {
        $this->history = [];
        $settings = new RecordingStreamSettings(2, false, '', sys_get_temp_dir());
        $streamer = new RecordingStreamer(
            $settings,
            new RecordingStreamGate($settings, new NullLogger()),
            $this->client(...($answer === null ? [] : [$answer])),
        );

        $action = new AudioAction(
            new ChannelApiProbe(),
            $streamer,
            new RecordingResponder(new HttpFactory()),
            new FixtureRecordingSource(),
            $this->session,
            new NullLogger(),
        );

        $request = (new ServerRequest('GET', '/admin/order58/recent-calls/audio'))->withQueryParams($override + [
            'store' => '1731',
            'session' => '23902889',
            'date' => '2026-11-25',
            'channel' => 'mixed',
        ]);

        return $action($range === null ? $request : $request->withHeader('Range', $range));
    }

    /** @param array<string, string> $query */
    private function receipt(ReceiptClientInterface $client, array $query): ResponseInterface
    {
        $factory = new HttpFactory();
        $action = new ReceiptAction(new RecentCallsReceipts($this->session), $this->session, $client, $factory, $factory);

        return $action((new ServerRequest('GET', '/admin/order58/recent-calls/receipt'))->withQueryParams($query));
    }

    private function wav(): string
    {
        return 'RIFF' . pack('V', 136) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', 100) . str_repeat("\0", 100);
    }
}

/** A receipt client that records what it was asked for, and answers with a tiny PNG or a failure. */
final class RecordingReceipts implements ReceiptClientInterface
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    public function __construct(private ?ReceiptFailed $failure = null) {}

    public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
    {
        $this->asked[] = [$accountId, $sourceOrderId];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ReceiptDelivery("\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR-pixels", 'image/png');
    }
}
