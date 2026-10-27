<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Web\RecordingAudio\RecordingResponder;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

use function strlen;

/**
 * A fetched recording as HTTP: whole, one range of it, or refused — and never cacheable.
 */
final class RecordingResponderTest extends Unit
{
    private const BODY = 'RIFF0123456789';

    public function testTheWholeFileIsA200WithItsLength(): void
    {
        $response = $this->respond();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::BODY, (string) $response->getBody());
        self::assertSame('14', $response->getHeaderLine('Content-Length'));
        self::assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
        self::assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('inline; filename="order-1-call-2-1.wav"', $response->getHeaderLine('Content-Disposition'));
    }

    /**
     * @dataProvider ranges
     */
    public function testARangeIsA206WithExactlyThoseBytes(string $header, string $bytes, string $contentRange): void
    {
        $response = $this->respond($header);

        self::assertSame(206, $response->getStatusCode());
        self::assertSame($bytes, (string) $response->getBody());
        self::assertSame($contentRange, $response->getHeaderLine('Content-Range'));
        self::assertSame((string) strlen($bytes), $response->getHeaderLine('Content-Length'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function ranges(): array
    {
        return [
            'the start, as Safari asks' => ['bytes=0-1', 'RI', 'bytes 0-1/14'],
            'open ended, as Chrome asks' => ['bytes=0-', self::BODY, 'bytes 0-13/14'],
            'a seek' => ['bytes=10-', '6789', 'bytes 10-13/14'],
            'the last bytes' => ['bytes=-3', '789', 'bytes 11-13/14'],
            'an end past the file is clamped' => ['bytes=12-99', '89', 'bytes 12-13/14'],
        ];
    }

    public function testARangeThatCannotBeSatisfiedIs416WithTheTrueLength(): void
    {
        $response = $this->respond('bytes=50-');

        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */14', $response->getHeaderLine('Content-Range'));
    }

    /** Several ranges, or a malformed one, are answered with the whole file, as RFC 9110 allows. */
    public function testAnythingElseIsTheWholeFile(): void
    {
        foreach (['bytes=0-1,4-5', 'items=0-1', 'bytes=-', 'bytes=abc'] as $header) {
            self::assertSame(200, $this->respond($header)->getStatusCode(), $header);
        }
    }

    public function testADownloadIsAnAttachment(): void
    {
        self::assertSame(
            'attachment; filename="order-1-call-2-1.wav"',
            $this->respond('', true)->getHeaderLine('Content-Disposition'),
        );
    }

    /** The name cannot carry a quote, a path or a header break. */
    public function testTheFileNameIsReducedToSafeCharacters(): void
    {
        $response = (new RecordingResponder(new HttpFactory()))->respond(
            Utils::streamFor(self::BODY),
            14,
            new ServerRequest('GET', '/'),
            false,
            "../evil\"\r\nX: y.wav",
        );

        self::assertSame('inline; filename="..evilXy.wav"', $response->getHeaderLine('Content-Disposition'));
    }

    public function testAProblemIsPlainTextAndNeverCached(): void
    {
        $response = (new RecordingResponder(new HttpFactory()))->problem(503, 'Busy.', 3);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('3', $response->getHeaderLine('Retry-After'));
        self::assertSame('Busy.', (string) $response->getBody());
    }

    private function respond(string $range = '', bool $download = false): ResponseInterface
    {
        $request = new ServerRequest('GET', '/recording');

        if ($range !== '') {
            $request = $request->withHeader('Range', $range);
        }

        return (new RecordingResponder(new HttpFactory()))->respond(
            Utils::streamFor(self::BODY),
            14,
            $request,
            $download,
            'order-1-call-2-1.wav',
        );
    }
}
