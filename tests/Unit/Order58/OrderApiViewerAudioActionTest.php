<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\RecordingStream\RecordingStreamer;
use App\Order58\Application\RecordingStream\RecordingStreamGate;
use App\Order58\Application\RecordingStream\RecordingStreamSettings;
use App\Order58\Web\OrderApiViewer\AudioAction;
use App\Order58\Web\RecordingAudio\RecordingResponder;
use Codeception\Test\Unit;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

use function pack;
use function str_repeat;
use function sys_get_temp_dir;

/**
 * The Order API viewer's audio endpoint, after its fetching moved to the shared streamer.
 *
 * It must still answer the way it did on the production server: the same address built from a session
 * id and a date, the same file name, and the same sentences when the provider refuses.
 */
final class OrderApiViewerAudioActionTest extends Unit
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testItStillBuildsTheAddressFromTheSessionAndDate(): void
    {
        $response = $this->call(['session' => '21403649', 'time' => '2026-07-29'], new Response(200, [], $this->wav()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="21403649.wav"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(
            'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
                . '?time=2026-07-29&company=SWCC&name=21403649',
            (string) $this->history[0]['request']->getUri(),
        );
    }

    public function testDownloadStillSavesUnderTheSessionId(): void
    {
        $response = $this->call(
            ['session' => '21403649', 'time' => '2026-07-29', 'download' => '1'],
            new Response(200, [], $this->wav()),
        );

        self::assertSame('attachment; filename="21403649.wav"', $response->getHeaderLine('Content-Disposition'));
    }

    public function testAnInvalidReferenceIsStillA400(): void
    {
        $response = $this->call(['session' => 'abc', 'time' => '2026-02-31']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->history, 'nothing was requested');
    }

    public function testAMissingRecordingStillSaysSoInTheSameWords(): void
    {
        $response = $this->call(['session' => '21403649', 'time' => '2026-07-29'], new Response(404));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('answered HTTP 404 for session 21403649 on 2026-07-29', (string) $response->getBody());
        self::assertStringContainsString('No recording exists at that address', (string) $response->getBody());
    }

    public function testARefusalStillNamesTheAllowlist(): void
    {
        $response = $this->call(['session' => '21403649', 'time' => '2026-07-29'], new Response(403));

        self::assertStringContainsString('outbound IP may not be whitelisted', (string) $response->getBody());
    }

    public function testANonWavBodyIsStillRefused(): void
    {
        $response = $this->call(['session' => '21403649', 'time' => '2026-07-29'], new Response(200, [], '<html>error</html>'));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('but the body is not a WAV file', (string) $response->getBody());
    }

    /** @param array<string, string> $query */
    private function call(array $query, Response ...$answers): ResponseInterface
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $settings = new RecordingStreamSettings(2, false, '', sys_get_temp_dir());

        $action = new AudioAction(
            new RecordingStreamer(
                $settings,
                new RecordingStreamGate($settings, new NullLogger()),
                new Client(['handler' => $stack]),
            ),
            new RecordingResponder(new HttpFactory()),
        );

        return $action((new ServerRequest('GET', '/admin/order-api-viewer/audio'))->withQueryParams($query));
    }

    private function wav(): string
    {
        return 'RIFF' . pack('V', 136) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            . 'data' . pack('V', 100) . str_repeat("\0", 100);
    }
}
