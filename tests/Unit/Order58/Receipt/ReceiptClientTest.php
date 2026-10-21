<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Receipt;

use App\Order58\Client\Receipt\HttpReceiptClient;
use App\Order58\Client\Receipt\ReceiptFailed;
use App\Order58\Client\Receipt\ReceiptSettings;
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
 * The Order58 Receipt client. **No test here reaches the network.**
 *
 * The PSR-18 client is a double in every case, so "makes no live call" is structural rather than a
 * promise — which matters because the live endpoint is a third party's production API and a test suite
 * has no business calling it.
 *
 * ## What is really being guarded
 *
 * This client's answer is handed to a browser. So the interesting cases are not the happy ones: they
 * are every shape of upstream answer that could put somebody else's markup, somebody else's script, or
 * an unbounded body onto this application's origin.
 */
final class ReceiptClientTest extends Unit
{
    private const ACCOUNT = 1731;
    private const ORDER = 16800401;

    /** A one-pixel PNG, so "it is really the bytes" can be asserted rather than assumed. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR";

    // ------------------------------------------------------------------ before any socket

    public function testAnUnconfiguredTokenMakesNoRequestAtAll(): void
    {
        $http = $this->http(new Response(200, ['Content-Type' => 'image/png'], self::PNG));

        try {
            $this->client($http, token: '')->fetch(self::ACCOUNT, self::ORDER);
            $this->fail('An unconfigured client must refuse before opening a socket.');
        } catch (ReceiptFailed $e) {
            $this->assertStringContainsString('ORDER58_RECEIPT_API_TOKEN', $e->getMessage());
        }

        // The point of the test: nothing was sent.
        $this->assertNull($http->request);
    }

    /** Names, never values — a misconfiguration message is read by whoever has the wrong token. */
    public function testTheFailureNamesTheVariableAndNotItsValue(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/ORDER58_RECEIPT_API_URL/');

        $this->client($this->http(new Response(200)), url: '')->fetch(self::ACCOUNT, self::ORDER);
    }

    // ------------------------------------------------------------------ the request we send

    public function testTheRequestCarriesTheTokenTheVerbAndBothIdentifiers(): void
    {
        $http = $this->http(new Response(200, ['Content-Type' => 'image/png'], self::PNG));

        $this->client($http)->fetch(self::ACCOUNT, self::ORDER);

        $request = $http->request;
        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true);

        // Both as strings, which is the shape the orders endpoint of this same API takes.
        $this->assertSame(['account_id' => '1731', 'order_id' => '16800401'], $body);
    }

    /** The account is the caller's, never the order's — a mix-up here is a cross-store leak. */
    public function testTheAccountSentIsTheOneAskedFor(): void
    {
        $http = $this->http(new Response(200, ['Content-Type' => 'image/png'], self::PNG));

        $this->client($http)->fetch(987655820, self::ORDER);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) ($http->request?->getBody() ?? '{}'), true);

        $this->assertSame('987655820', $body['account_id']);
    }

    // ------------------------------------------------------------------ what we will serve

    public function testAnImageComesBackAsItsOwnBytes(): void
    {
        $receipt = $this->client($this->http(
            new Response(200, ['Content-Type' => 'image/png'], self::PNG),
        ))->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame(self::PNG, $receipt->bytes);
        $this->assertSame('image/png', $receipt->contentType);
        $this->assertSame('receipt-16800401.png', $receipt->downloadName(self::ORDER));
    }

    /** A charset parameter is not a different type, and a provider may add one at any time. */
    public function testAContentTypeWithParametersIsStillRecognised(): void
    {
        $receipt = $this->client($this->http(
            new Response(200, ['Content-Type' => 'IMAGE/PNG; charset=binary'], self::PNG),
        ))->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame('image/png', $receipt->contentType);
    }

    public function testAPdfIsServed(): void
    {
        $receipt = $this->client($this->http(
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4'),
        ))->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame('receipt-16800401.pdf', $receipt->downloadName(self::ORDER));
    }

    // ------------------------------------------------------------------ the header is not believed

    /**
     * The live endpoint's actual behaviour, pinned.
     *
     * Measured against it on 2026-10-21: it returns a real PNG — correct signature, ~96 KB — under
     * `Content-Type: application/json; charset=UTF-8`. Trusting that header put image bytes through
     * the JSON parser and reported a perfectly good receipt as an unsupported type.
     */
    public function testAPngMislabelledAsJsonIsStillServedAsAPng(): void
    {
        $receipt = $this->client($this->http(new Response(
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            self::PNG,
        )))->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame('image/png', $receipt->contentType);
        $this->assertSame(self::PNG, $receipt->bytes);
    }

    /** Every signature on the allow-list, each under a header that contradicts it. */
    public function testEachSupportedSignatureIsRecognisedWhateverTheHeaderClaims(): void
    {
        $bodies = [
            'image/png' => self::PNG,
            'image/jpeg' => "\xFF\xD8\xFF\xE0" . 'JFIF',
            'image/gif' => 'GIF89a' . str_repeat("\x00", 8),
            'image/webp' => 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat("\x00", 4),
            'application/pdf' => '%PDF-1.4 trailer',
        ];

        foreach ($bodies as $expected => $body) {
            $receipt = $this->client($this->http(new Response(
                200,
                ['Content-Type' => 'application/json'],
                $body,
            )))->fetch(self::ACCOUNT, self::ORDER);

            $this->assertSame($expected, $receipt->contentType, 'for ' . $expected);
        }
    }

    /**
     * The limit of the sniffing, and the reason it is safe.
     *
     * It recognises only the signatures of types already on the allow-list, so there is no body it
     * can turn into something we would not otherwise serve. HTML matches nothing, falls through to
     * the declared type, and is refused exactly as it was before sniffing existed — even when the
     * header is the friendliest one a attacker could pick.
     */
    public function testSniffingCannotTurnHtmlIntoSomethingServable(): void
    {
        foreach (['text/html', 'image/png', 'application/json'] as $claimed) {
            try {
                $this->client($this->http(new Response(
                    200,
                    ['Content-Type' => $claimed],
                    '<script>fetch("/admin/topics")</script>',
                )))->fetch(self::ACCOUNT, self::ORDER);

                $this->fail('HTML claimed as "' . $claimed . '" must never be served.');
            } catch (ReceiptFailed $e) {
                $this->assertStringNotContainsString('<script', $e->getMessage());
            }
        }
    }

    /** A genuine JSON body still takes the JSON path — sniffing does not shadow it. */
    public function testRealJsonIsStillFollowedRatherThanSniffed(): void
    {
        $http = new SequencedHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'url' => 'https://cdn.example.invalid/receipts/2.png',
            ])),
            // The CDN mislabels too, and is treated the same way.
            new Response(200, ['Content-Type' => 'application/json'], self::PNG),
        ]);

        $receipt = $this->client($http)->fetch(self::ACCOUNT, self::ORDER);

        $this->assertCount(2, $http->sent);
        $this->assertSame('image/png', $receipt->contentType);
    }

    /**
     * The one that matters most.
     *
     * Upstream HTML on our origin is upstream script on our origin: it would read this application's
     * cookies and call its endpoints as the signed-in administrator. It is refused by type, not
     * sanitised and not sniffed.
     */
    public function testHtmlIsRefusedRatherThanRendered(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/does not display/');

        $this->client($this->http(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<script>fetch("/admin/topics")</script>',
        )))->fetch(self::ACCOUNT, self::ORDER);
    }

    /**
     * No header and nothing recognisable in the bytes: refused.
     *
     * A PNG with no header is *not* this case — its signature identifies it and it is served, which
     * is the whole point of reading the bytes. What is refused is a body that neither says nor shows
     * what it is.
     */
    public function testAnswersThatNeitherSayNorShowWhatTheyAreAreRefused(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/did not say what kind of file/');

        $this->client($this->http(new Response(200, [], 'not a file of any kind')))
            ->fetch(self::ACCOUNT, self::ORDER);
    }

    public function testAnImageWithNoHeaderAtAllIsStillRecognised(): void
    {
        $receipt = $this->client($this->http(new Response(200, [], self::PNG)))
            ->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame('image/png', $receipt->contentType);
    }

    public function testAnEmptyBodyIsAFailureNotAnEmptyImage(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/empty/');

        $this->client($this->http(new Response(200, ['Content-Type' => 'image/png'], '')))
            ->fetch(self::ACCOUNT, self::ORDER);
    }

    public function testABodyPastTheCeilingIsAbandoned(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/larger than this server will read/');

        $this->client(
            $this->http(new Response(200, ['Content-Type' => 'image/png'], str_repeat('x', 5000))),
            maxBytes: 1024,
        )->fetch(self::ACCOUNT, self::ORDER);
    }

    // ------------------------------------------------------------------ a JSON answer

    public function testJsonNamingAnHttpsImageIsFollowedExactlyOnce(): void
    {
        $http = new SequencedHttpClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'url' => 'https://cdn.example.invalid/receipts/1.png',
            ])),
            new Response(200, ['Content-Type' => 'image/png'], self::PNG),
        ]);

        $receipt = $this->client($http)->fetch(self::ACCOUNT, self::ORDER);

        $this->assertSame(self::PNG, $receipt->bytes);
        $this->assertCount(2, $http->sent);
        $this->assertSame('GET', $http->sent[1]->getMethod());
        // The credential is for the API, not for whatever host the API points at.
        $this->assertSame('', $http->sent[1]->getHeaderLine('Authorization'));
    }

    /**
     * A relative path would resolve against our own host and turn this into a request to ourselves;
     * a plain-http one would put a redirect on the wire in clear. Neither is followed.
     */
    public function testJsonNamingANonHttpsLocationIsNotFollowed(): void
    {
        foreach (['/receipts/1.png', 'http://cdn.example.invalid/1.png'] as $url) {
            $http = new SequencedHttpClient([
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['url' => $url])),
            ]);

            try {
                $this->client($http)->fetch(self::ACCOUNT, self::ORDER);
                $this->fail('"' . $url . '" must not be followed.');
            } catch (ReceiptFailed) {
                // Exactly one request: the follow never happened.
                $this->assertCount(1, $http->sent);
            }
        }
    }

    public function testJsonCarryingAnErrorReadsAsNoReceipt(): void
    {
        $this->expectException(ReceiptFailed::class);
        $this->expectExceptionMessageMatches('/No receipt is available/');

        $this->client($this->http(new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['error' => 'not printed']),
        )))->fetch(self::ACCOUNT, self::ORDER);
    }

    // ------------------------------------------------------------------ upstream failures

    public function testEachStatusGetsAMessageAnAdministratorCanAct(): void
    {
        $cases = [
            401 => 'credentials',
            403 => 'credentials',
            404 => 'no receipt',
            429 => 'rate limiting',
            500 => 'unavailable',
            418 => 'refused the request',
        ];

        foreach ($cases as $status => $expected) {
            try {
                $this->client($this->http(new Response($status)))->fetch(self::ACCOUNT, self::ORDER);
                $this->fail('HTTP ' . $status . ' must fail.');
            } catch (ReceiptFailed $e) {
                $this->assertStringContainsStringIgnoringCase($expected, $e->getMessage(), 'HTTP ' . $status);
            }
        }
    }

    /** A transport failure's message can carry the full request URI, so it is never forwarded. */
    public function testATransportFailureSaysNothingAboutTheRequest(): void
    {
        $exploding = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('cURL error 6: could not resolve https://secret.example.invalid') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->client($exploding)->fetch(self::ACCOUNT, self::ORDER);
            $this->fail('A transport failure must surface as a ReceiptFailed.');
        } catch (ReceiptFailed $e) {
            $this->assertStringNotContainsString('secret.example.invalid', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    /** No message anywhere in this class may carry the credential. */
    public function testNoFailureMessageEverCarriesTheToken(): void
    {
        $responses = [
            new Response(401),
            new Response(500),
            new Response(200, ['Content-Type' => 'text/html'], 'x'),
            new Response(200, ['Content-Type' => 'image/png'], ''),
        ];

        foreach ($responses as $response) {
            try {
                $this->client($this->http($response))->fetch(self::ACCOUNT, self::ORDER);
            } catch (ReceiptFailed $e) {
                $this->assertStringNotContainsString('test-token', $e->getMessage());
                $this->assertStringNotContainsString('Bearer', $e->getMessage());
            }
        }
    }

    private function http(ResponseInterface $response): RecordingHttpClient
    {
        return new RecordingHttpClient($response);
    }

    private function client(
        ClientInterface $http,
        string $url = 'https://example.invalid/api/order-data/receipt',
        string $token = 'test-token',
        int $maxBytes = 4194304,
    ): HttpReceiptClient {
        $psr7 = new HttpFactory();

        return new HttpReceiptClient($http, $psr7, $psr7, new ReceiptSettings(
            url: $url,
            token: $token,
            connectTimeoutSeconds: 5,
            timeoutSeconds: 20,
            maxResponseBytes: $maxBytes,
        ));
    }
}

/**
 * A PSR-18 double that answers a scripted sequence and keeps every request.
 *
 * {@see RecordingHttpClient} answers one fixture, which cannot express the two-step JSON path: a
 * client that returned the same body twice would make "it followed the link" indistinguishable from
 * "it read the first answer again".
 */
final class SequencedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $next = array_shift($this->responses);

        if ($next === null) {
            throw new RuntimeException('The client made more requests than the test scripted.');
        }

        return $next;
    }
}
