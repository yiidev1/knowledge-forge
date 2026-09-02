<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Web\TestRecordingApis\DownloadAction;
use App\Order58\Web\TestRecordingApis\RecordingApiProbe;
use Codeception\Test\Unit;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\ServerRequest;
use HttpSoft\Message\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function str_repeat;
use function strlen;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The download endpoint of the admin recording API test tool, driven against a Guzzle mock handler so the
 * external host is never contacted.
 *
 * The behaviour worth pinning down is not "it downloads" but everything it refuses to download: an
 * upstream error, an HTML body wearing a 200, or a filename the upstream chose. Each of those, if it got
 * through, would put a file on an administrator's disk that lies about what it is.
 */
final class RecordingDownloadTest extends Unit
{
    private const VALID = [
        'call_session_id' => '22049499',
        'time' => '2026-03-11',
        'company' => 'SWCC',
        'name' => 'test',
    ];

    /** A stand-in for the real 3.8 MB recording: enough bytes to be a stream, with NULs like real audio. */
    private const AUDIO = "RIFF\0\0\0\0WAVEfmt \0\0\0\0";

    /**
     * @param array<string, string> $query
     */
    private function download(ResponseInterface|RuntimeException $upstream, array $query = self::VALID): ResponseInterface
    {
        $handler = new MockHandler([$upstream]);
        $probe = new RecordingApiProbe(
            httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]),
            baseUrl: 'https://order58.test/api/external/recording',
        );

        $action = new DownloadAction($probe, new ResponseFactory());

        return $action((new ServerRequest('GET', '/admin/order58/test-recording-apis/download'))
            ->withQueryParams($query));
    }

    /** 1. A successful binary response becomes a real download. */
    public function testSuccessfulBinaryResponseProducesADownload(): void
    {
        $audio = str_repeat(self::AUDIO, 1000);
        $response = $this->download(new GuzzleResponse(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="test.wav"',
            'Content-Length' => (string) strlen($audio),
        ], $audio));

        assertSame(200, $response->getStatusCode());
        assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        assertSame((string) strlen($audio), $response->getHeaderLine('Content-Length'));
        assertSame($audio, (string) $response->getBody());
    }

    /** 2. A safe upstream filename is the one the browser is given. */
    public function testSafeUpstreamFilenameIsUsed(): void
    {
        $response = $this->download(new GuzzleResponse(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="test.wav"',
        ], self::AUDIO));

        assertSame('attachment; filename="test.wav"', $response->getHeaderLine('Content-Disposition'));
    }

    /**
     * 3. An unsafe upstream filename never reaches the header — traversal, absolute paths, quote-escapes
     *    and header injection all collapse to the generated fallback or a stripped-down safe name.
     *
     * @dataProvider unsafeFilenames
     */
    public function testUnsafeUpstreamFilenameIsSanitized(string $disposition, string $expected): void
    {
        $response = $this->download(new GuzzleResponse(200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => $disposition,
        ], self::AUDIO));

        assertSame(sprintf('attachment; filename="%s"', $expected), $response->getHeaderLine('Content-Disposition'));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public function unsafeFilenames(): iterable
    {
        // Traversal and absolute paths keep only the final segment, which must still be safe.
        yield 'traversal' => ['attachment; filename="../../../etc/passwd"', 'passwd'];
        yield 'absolute path' => ['attachment; filename="/var/www/secret.wav"', 'secret.wav'];
        yield 'windows path' => ['attachment; filename="C:\\\\Windows\\\\evil.wav"', 'evil.wav'];
        // RFC 5987 percent-encoding is the one route by which a CRLF can reach us — PSR-7 refuses to
        // build a header containing a literal one — so the decoded value is filtered, not trusted.
        yield 'encoded crlf injection' => ["attachment; filename*=UTF-8''a%0D%0AX-Evil:%201.wav", 'aX-Evil1.wav'];
        // A quote cannot escape the quoted string we emit.
        yield 'quote escape' => ['attachment; filename="ev"il.wav"', 'ev'];
        // Nothing safe survives, so the fallback is generated from the validated call session id.
        yield 'only separators' => ['attachment; filename="../../"', 'recording-22049499.wav'];
        yield 'hidden file' => ['attachment; filename=".bashrc"', 'bashrc'];
        yield 'missing header' => ['', 'recording-22049499.wav'];
        yield 'no filename param' => ['attachment', 'recording-22049499.wav'];
    }

    /**
     * 4. An upstream error is never dressed up as audio — this is the live 403 the IP allowlist returns.
     *
     * @dataProvider upstreamErrors
     */
    public function testUpstreamErrorDoesNotProduceAnAudioDownload(int $status, string $body): void
    {
        $response = $this->download(new GuzzleResponse($status, ['Content-Type' => 'text/plain'], $body));

        assertSame(502, $response->getStatusCode());
        assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        assertStringNotContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        // The operator sees the upstream status and its own words.
        assertStringContainsString((string) $status, (string) $response->getBody());
        assertStringContainsString($body, (string) $response->getBody());
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public function upstreamErrors(): iterable
    {
        yield '403 allowlist' => [403, '403 Forbidden - IP not authorized: 150.107.241.124'];
        yield '404' => [404, 'Recording not found'];
        yield '500' => [500, 'Internal Server Error'];
    }

    /** 4b. A 200 carrying HTML is an error in disguise, and must not be saved as a recording. */
    public function testHtmlBodyWithA200IsRefused(): void
    {
        $response = $this->download(new GuzzleResponse(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="test.wav"',
        ], '<html><body>Session expired</body></html>'));

        assertSame(502, $response->getStatusCode());
        assertStringNotContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        assertStringContainsString('body is text, not a recording', (string) $response->getBody());
    }

    /** 4c. A transport failure is reported here, not thrown into the global error handler. */
    public function testTransportFailureIsReportedAsADiagnostic(): void
    {
        $response = $this->download(new RuntimeException('cURL error 6: Could not resolve host'));

        assertSame(502, $response->getStatusCode());
        assertStringNotContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        assertStringContainsString('Could not resolve host', (string) $response->getBody());
    }

    /**
     * 5. Malformed input is rejected before any call is made. The mock handler holds a valid recording:
     *    if the endpoint were to call out anyway, these would come back as 200 downloads.
     *
     * @dataProvider malformedInput
     */
    public function testMalformedInputIsRejectedWithoutCallingTheApi(array $query, string $expected): void
    {
        $response = $this->download(
            new GuzzleResponse(200, ['Content-Type' => 'application/octet-stream'], self::AUDIO),
            $query,
        );

        assertSame(400, $response->getStatusCode());
        assertStringNotContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        assertStringContainsString($expected, (string) $response->getBody());
        assertStringContainsString('nothing was sent to the API', (string) $response->getBody());
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public function malformedInput(): iterable
    {
        yield 'non-numeric id' => [[...self::VALID, 'call_session_id' => '22a'], 'digits only'];
        yield 'empty id' => [[...self::VALID, 'call_session_id' => ''], 'digits only'];
        yield 'id with traversal' => [[...self::VALID, 'call_session_id' => '../1'], 'digits only'];
        yield 'impossible date' => [[...self::VALID, 'time' => '2026-02-31'], 'YYYY-MM-DD'];
        yield 'malformed date' => [[...self::VALID, 'time' => '11-03-2026'], 'YYYY-MM-DD'];
        yield 'empty company' => [[...self::VALID, 'company' => ''], 'Company is required'];
        yield 'overlong name' => [[...self::VALID, 'name' => str_repeat('x', 101)], 'Name is required'];
    }

    /** The request the endpoint actually makes: encoded, unauthenticated, and aimed at the fetch route. */
    public function testTheOutboundRequestIsEncodedAndCarriesNoCredential(): void
    {
        $handler = new MockHandler([
            new GuzzleResponse(200, ['Content-Type' => 'application/octet-stream'], self::AUDIO),
        ]);
        $probe = new RecordingApiProbe(
            httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]),
            baseUrl: 'https://order58.test/api/external/recording',
        );

        $action = new DownloadAction($probe, new ResponseFactory());
        $action((new ServerRequest('GET', '/download'))->withQueryParams([...self::VALID, 'company' => 'S&W CC']));

        $sent = $handler->getLastRequest();
        assertTrue($sent !== null);
        assertSame(
            '/api/external/recording/fetch/22049499',
            $sent->getUri()->getPath(),
        );
        // The ampersand is encoded into the value, not left to split the query into another parameter.
        assertStringContainsString('company=S%26W+CC', $sent->getUri()->getQuery());
        assertSame('', $sent->getHeaderLine('Authorization'));
    }
}
