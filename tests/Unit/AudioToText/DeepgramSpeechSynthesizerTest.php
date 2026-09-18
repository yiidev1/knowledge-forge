<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Infrastructure\Tts\DeepgramSpeechSynthesizer;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function json_decode;
use function str_repeat;

/**
 * The Deepgram text-to-speech client. **No test here reaches the network.**
 *
 * Two things are being protected. The first is the shape of the outgoing request — particularly
 * `container=none`, which is what makes joining chunks a byte concatenation instead of an
 * audio-processing problem, and `sample_rate`, which the encoder is separately told the raw file
 * contains. If those two ever disagreed the audio would play at the wrong speed.
 *
 * The second is the API key. It is revealed exactly twice in the class under test — once for the
 * Authorization header, once to seed the redactor that protects the first — and this file asserts it
 * appears nowhere else, including in the places a third party controls.
 */
final class DeepgramSpeechSynthesizerTest extends TestCase
{
    private const KEY = 'dg_live_0123456789abcdef0123456789abcdef';

    // ------------------------------------------------------------------ the request

    public function testItAsksForRawPcmAtTheConfiguredRate(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));

        $this->synthesizer($client)->synthesize('Ready in 25 minutes.', 'aura-2-arcas-en');

        $query = $client->query();

        $this->assertStringContainsString('model=aura-2-arcas-en', $query);
        $this->assertStringContainsString('encoding=linear16', $query);
        // The load-bearing one: a container would bring a header whose length fields are placeholders in
        // a streamed response, and chunks could then not simply be concatenated.
        $this->assertStringContainsString('container=none', $query);
        $this->assertStringContainsString('sample_rate=24000', $query);
    }

    public function testItPostsTheTextAsJsonWithTheTokenScheme(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));

        $this->synthesizer($client)->synthesize('Two egg foo young.', 'aura-2-thalia-en');

        $request = $client->request;

        $this->assertNotNull($request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('Token ' . self::KEY, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"text":"Two egg foo young."}', (string) $request->getBody());
    }

    /** The text is sent exactly as given — no escaping of characters that need none, no reordering. */
    public function testTheTextIsSentVerbatim(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));
        $text = 'Two egg foo young, no MSG, $43 and 45, "extra spicy" — ok?';

        $this->synthesizer($client)->synthesize($text, 'aura-2-thalia-en');

        $decoded = json_decode((string) $client->request?->getBody(), true);

        $this->assertSame($text, $decoded['text'] ?? null);
    }

    public function testTheAudioBytesAreReturnedUnchanged(): void
    {
        $audio = str_repeat("\x01\x02", 2048);
        $client = new RecordingHttpClient(new Response(200, [], $audio));

        $this->assertSame($audio, $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en'));
    }

    // ------------------------------------------------------------------ failures, told apart

    /**
     * Each status maps to a distinct failure because each one has a different next move for an operator:
     * check the key, wait, fix the chunker, or wait for the provider.
     *
     * @dataProvider refusals
     */
    public function testEachRefusalCarriesItsOwnMessage(int $status, string $expectedFragment, bool $retryable): void
    {
        $client = new RecordingHttpClient(new Response($status, [], '{"err_msg":"nope"}'));

        try {
            $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en');
            $this->fail('A non-200 must not be treated as audio.');
        } catch (TtsException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
            $this->assertSame($retryable, $e->isRetryable());
        }
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function refusals(): array
    {
        return [
            'unauthorised' => [401, 'credentials', false],
            'forbidden — the key may not include text-to-speech' => [403, 'credentials', false],
            'payload too large' => [413, 'too long', false],
            'rate limited' => [429, 'rate limiting', true],
            'bad request' => [400, 'refused this request', false],
            'server error' => [500, 'temporarily unavailable', true],
            'bad gateway' => [502, 'temporarily unavailable', true],
        ];
    }

    /** A 200 with nothing usable in it would otherwise become a rendition that plays silence. */
    public function testAnEmptyBodyIsAFailureNotSilence(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], ''));

        $this->expectException(TtsException::class);
        $this->expectExceptionMessageMatches('/no audio/i');

        $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en');
    }

    public function testATruncatedBodyIsAFailure(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], 'short'));

        $this->expectException(TtsException::class);

        $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en');
    }

    /**
     * A transport failure is not a refusal: the request never got an answer, and the operator's next
     * move is the network rather than the key.
     */
    public function testAnUnreachableProviderIsDistinctFromARefusal(): void
    {
        $synthesizer = $this->synthesizer($this->throwingClient('Connection refused'));

        try {
            $synthesizer->synthesize('Hello.', 'aura-2-thalia-en');
            $this->fail('A transport failure must not pass silently.');
        } catch (TtsException $e) {
            $this->assertStringContainsString('could not reach', $e->getMessage());
            $this->assertTrue($e->isRetryable());
        }
    }

    // ------------------------------------------------------------------ the key never escapes

    /**
     * **The regression that already bit the transcription engine.**
     *
     * A failed response body is quoted into the log so an operator can diagnose it, and that body is
     * written by a third party — so nothing may assume what a remote service will echo back. Here it
     * matters twice over, because this exception's user-facing half is stored in `error_message` and
     * rendered on an admin page.
     */
    public function testTheApiKeyNeverAppearsInAnyFailureMessage(): void
    {
        $bodies = [
            'a body that echoes the key: ' . self::KEY,
            '{"error":"invalid Authorization: Token ' . self::KEY . '"}',
            '{"api_key":"' . self::KEY . '"}',
        ];

        foreach ([401, 400, 500] as $index => $status) {
            $client = new RecordingHttpClient(new Response($status, [], $bodies[$index]));

            try {
                $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en');
                $this->fail('Expected a failure for HTTP ' . $status);
            } catch (TtsException $e) {
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertStringNotContainsString(self::KEY, $e->technicalDetail());
                $this->assertStringNotContainsString(self::KEY, (string) $e);
            }
        }
    }

    public function testTheApiKeyNeverAppearsInATransportFailure(): void
    {
        $synthesizer = $this->synthesizer($this->throwingClient('cURL error: Token ' . self::KEY));

        try {
            $synthesizer->synthesize('Hello.', 'aura-2-thalia-en');
            $this->fail('Expected a transport failure.');
        } catch (TtsException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->technicalDetail());
        }
    }

    /** Redaction happens before truncation, so a key cannot survive by being cut in half. */
    public function testALongBodyIsRedactedBeforeItIsTruncated(): void
    {
        $body = str_repeat('x', 480) . self::KEY . str_repeat('y', 500);
        $client = new RecordingHttpClient(new Response(400, [], $body));

        try {
            $this->synthesizer($client)->synthesize('Hello.', 'aura-2-thalia-en');
            $this->fail('Expected a refusal.');
        } catch (TtsException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->technicalDetail());
            // And the key's own prefix, in case only part of it survived the cut.
            $this->assertStringNotContainsString('dg_live_0123456789', $e->technicalDetail());
        }
    }

    // ------------------------------------------------------------------ readiness

    public function testAnUnconfiguredServerIsReportedWithoutCallingTheProvider(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));
        $synthesizer = new DeepgramSpeechSynthesizer(
            AudioToTextSettingsFactory::create(ttsApiKey: ''),
            $client,
            new HttpFactory(),
            new HttpFactory(),
        );

        try {
            $synthesizer->assertReady();
            $this->fail('An empty key must not be reported as ready.');
        } catch (TtsException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
            $this->assertNull($client->request, 'Readiness must be answered from local configuration alone.');
        }
    }

    public function testTwoIdenticalVoicesAreRefusedAsAConfigurationProblem(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));
        $synthesizer = new DeepgramSpeechSynthesizer(
            AudioToTextSettingsFactory::create(
                ttsApiKey: self::KEY,
                ttsCustomerModel: 'aura-2-thalia-en',
                ttsAgentModel: 'aura-2-thalia-en',
            ),
            $client,
            new HttpFactory(),
            new HttpFactory(),
        );

        $this->expectException(TtsException::class);

        // Technically valid and practically useless: a mixed conversation in one voice cannot be
        // followed by ear, which is the only reason the mixed file exists.
        $synthesizer->assertReady();
    }

    public function testAConfiguredServerIsReady(): void
    {
        $client = new RecordingHttpClient(new Response(200, [], str_repeat("\0", 4096)));

        $this->synthesizer($client)->assertReady();

        $this->assertNull($client->request, 'Readiness must not open a socket.');
        $this->assertTrue(true);
    }

    private function synthesizer(ClientInterface $client): DeepgramSpeechSynthesizer
    {
        return new DeepgramSpeechSynthesizer(
            AudioToTextSettingsFactory::create(ttsApiKey: self::KEY),
            $client,
            new HttpFactory(),
            new HttpFactory(),
        );
    }

    private function throwingClient(string $message): ClientInterface
    {
        return new class ($message) implements ClientInterface {
            public function __construct(private readonly string $message) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ($this->message) extends RuntimeException implements ClientExceptionInterface {};
            }
        };
    }
}
