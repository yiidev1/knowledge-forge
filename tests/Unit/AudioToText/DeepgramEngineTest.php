<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Infrastructure\Transcription\DeepgramEngine;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function explode;
use function implode;
use function file_put_contents;
use function json_encode;
use function parse_str;
use function sprintf;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * The only class that knows Deepgram exists.
 *
 * **No test here reaches the network.** Every response is a fixture handed to a doubled PSR-18 client,
 * which is also what lets the outgoing request be inspected — the query-encoding tests assert the
 * **final URI the client was given**, not the array that produced it, because the bug this guards
 * against (`keyterm[0]=`) only appears at that last step.
 */
final class DeepgramEngineTest extends TestCase
{
    private const KEY = 'test-key-not-a-real-credential';

    private ?string $wav = null;

    protected function tearDown(): void
    {
        if ($this->wav !== null) {
            @unlink($this->wav);
            $this->wav = null;
        }
    }

    // ------------------------------------------------------------------ identity and readiness

    public function testItIsTheDeepgramProvider(): void
    {
        $this->assertSame(TranscriptionProvider::Deepgram, $this->engine($this->client())->provider());
    }

    public function testWithoutAKeyItIsUnavailable(): void
    {
        $this->assertFalse($this->engine($this->refusingClient(), apiKey: '')->isAvailable());
    }

    public function testWithAKeyItIsAvailable(): void
    {
        $this->assertTrue($this->engine($this->refusingClient())->isAvailable());
    }

    /**
     * **Readiness never opens a socket.**
     *
     * The client double fails the test if it is called at all. Verifying a credential by calling
     * Deepgram would put a network round trip inside the upload request that also asks this question,
     * and would report a provider outage as a misconfiguration.
     */
    public function testReadinessDoesNotCallDeepgram(): void
    {
        $engine = $this->engine($this->refusingClient());

        $engine->assertReady();
        $engine->isAvailable();

        $this->addToAssertionCount(1);
    }

    public function testAnUnconfiguredProviderFailsBeforeAnythingIsSent(): void
    {
        $engine = $this->engine($this->refusingClient(), apiKey: '');

        $this->expectException(AudioTranscriptionException::class);
        $this->expectExceptionMessageMatches('/not configured/i');

        $engine->assertReady();
    }

    /** The reason is the operator's business; the uploader is told who failed, not which key is unset. */
    public function testTheUnconfiguredMessageNeverNamesTheSetting(): void
    {
        try {
            $this->engine($this->refusingClient(), apiKey: '')->assertReady();
            $this->fail('An unconfigured provider must not report itself ready.');
        } catch (AudioTranscriptionException $e) {
            $this->assertStringNotContainsString('DEEPGRAM_API_KEY', $e->getMessage());
            $this->assertStringContainsString('DEEPGRAM_API_KEY', $e->technicalDetail());
        }
    }

    // ------------------------------------------------------------------ the outgoing request

    public function testTheRequestCarriesTheConfiguredModelLanguageAndFormatting(): void
    {
        $client = $this->client();
        $this->recognise($client);

        parse_str($client->query(), $query);

        $this->assertSame('nova-3', $query['model'] ?? null);
        $this->assertSame('multi', $query['language'] ?? null);
        $this->assertSame('true', $query['smart_format'] ?? null);
    }

    public function testItPostsToTheConfiguredEndpoint(): void
    {
        $client = $this->client();
        $this->recognise($client);

        $this->assertSame('POST', $client->request?->getMethod());
        $this->assertSame('/v1/listen', $client->request?->getUri()->getPath());
        $this->assertSame('api.deepgram.com', $client->request?->getUri()->getHost());
    }

    public function testTheKeyIsSentAsATokenAuthorizationHeader(): void
    {
        $client = $this->client();
        $this->recognise($client);

        $this->assertSame('Token ' . self::KEY, $client->request?->getHeaderLine('Authorization'));
    }

    /**
     * **The encoding test.** Repeated keys, on the final URI.
     *
     * `http_build_query()` would produce `keyterm%5B0%5D=`, which Deepgram reads as a parameter called
     * `keyterm[0]`; comma-joining is not the format either. Both wrong answers are asserted against
     * explicitly, because both look plausible in a diff.
     */
    public function testKeytermsAreRepeatedQueryParametersOnTheFinalUri(): void
    {
        $client = $this->client();
        $this->recognise($client, ['wonton', 'lo mein', 'General Tso']);

        $query = $client->query();

        $this->assertStringContainsString('keyterm=wonton', $query);
        $this->assertStringContainsString('keyterm=lo%20mein', $query, 'A multi-word term is percent-encoded.');
        $this->assertStringContainsString('keyterm=General%20Tso', $query);

        $this->assertSame(3, substr_count($query, 'keyterm='), 'One parameter per term, none merged.');

        $this->assertStringNotContainsString('keyterm[', $query, 'Array-indexed keys are rejected by Deepgram.');
        $this->assertStringNotContainsString('keyterm%5B', $query);
        $this->assertStringNotContainsString('wonton,', $query, 'Keyterms are never comma-joined.');
    }

    public function testNoKeytermParameterIsSentWhenThereAreNone(): void
    {
        $client = $this->client();
        $this->recognise($client);

        $this->assertStringNotContainsString('keyterm', $client->query());
    }

    /**
     * Keyterm Prompting is a Nova-3 feature; sending it to an older model is a rejected request, not a
     * silent no-op. A deployment pinned to one transcribes without hints rather than failing every job.
     */
    public function testKeytermsAreWithheldFromAModelThatCannotAcceptThem(): void
    {
        $client = $this->client();
        $this->recognise($client, ['wonton'], model: 'nova-2');

        $this->assertStringNotContainsString('keyterm', $client->query());
    }

    /**
     * An over-budget keyterm set still transcribes.
     *
     * The estimate is advisory: the request is built and sent, and Deepgram decides. Blocking here
     * would refuse a configuration Deepgram might well have accepted.
     */
    public function testAnOverBudgetKeytermSetStillSendsTheRequest(): void
    {
        $terms = [];
        for ($i = 0; $i < 60; $i++) {
            $terms[] = 'phrase' . $i . ' word word word word word word word word word';
        }

        $keyterms = DeepgramKeyterms::fromList($terms);
        $this->assertGreaterThan(DeepgramKeyterms::ADVISORY_TOKEN_BUDGET, $keyterms->estimatedTokens());

        $client = $this->client();
        $result = $this->recognise($client, $terms);

        $this->assertSame('Two wonton soups please.', $result->text);
        $this->assertSame(60, substr_count($client->query(), 'keyterm='));
    }

    // ------------------------------------------------------------------ numerals

    /**
     * `numerals=true` reaches the wire when the setting is on.
     *
     * Asserted on the final URI, like every other query test here, because that is the only place an
     * encoding mistake becomes visible.
     */
    public function testNumeralsIsSentWhenEnabled(): void
    {
        $client = $this->client();
        $this->recognise($client, numerals: true);

        parse_str($client->query(), $query);

        $this->assertSame('true', $query['numerals'] ?? null);
    }

    /**
     * And is **omitted entirely** when off — not sent as `numerals=false`.
     *
     * False is Deepgram's own default, so a deployment that has not opted in must send byte-for-byte
     * the request it sent before this setting existed. That is what makes turning it on a
     * one-variable change whose effect can actually be attributed.
     */
    public function testNumeralsIsOmittedWhenDisabled(): void
    {
        $client = $this->client();
        $this->recognise($client);

        $this->assertStringNotContainsString('numerals', $client->query());
    }

    /** Off unless asked for, matching Deepgram's default and this project's SPEC. */
    public function testNumeralsIsOffByDefault(): void
    {
        $this->assertFalse(AudioToTextSettingsFactory::create()->deepgram->numerals);
    }

    /** Enabling it changes nothing else about the request. */
    public function testNumeralsDoesNotDisturbTheOtherParameters(): void
    {
        $withOut = $this->client();
        $this->recognise($withOut, ['wonton', 'lo mein']);

        $withIn = $this->client();
        $this->recognise($withIn, ['wonton', 'lo mein'], numerals: true);

        $this->assertSame(
            $withOut->query() . '&numerals=true',
            $this->moveNumeralsToEnd($withIn->query()),
            'numerals must be the only difference between the two requests.',
        );
    }

    /**
     * The engine emits `numerals` before the keyterms; this rebuilds the same query with it moved to
     * the end so the comparison above is about *content* rather than parameter order.
     */
    private function moveNumeralsToEnd(string $query): string
    {
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair !== 'numerals=true') {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept) . '&numerals=true';
    }

    /** `language` is deployment configuration, and the engine sends what it was given. */
    public function testASingleLanguageDeploymentSendsThatLanguage(): void
    {
        $client = $this->client();
        $this->recognise($client, language: 'en');

        parse_str($client->query(), $query);

        $this->assertSame('en', $query['language'] ?? null);
    }

    /** Transcription only — the local diarizer keeps speaker separation. */
    public function testNoDiarizationOrExtraAnalysisIsEverRequested(): void
    {
        $client = $this->client();
        $this->recognise($client, ['wonton']);

        $query = $client->query();

        foreach (['diarize', 'detect_entities', 'summarize', 'redact', 'utterances'] as $unwanted) {
            $this->assertStringNotContainsString($unwanted, $query);
        }
    }

    // ------------------------------------------------------------------ response mapping

    public function testTheTranscriptIsTakenFromTheFirstAlternative(): void
    {
        $this->assertSame('Two wonton soups please.', $this->recognise($this->client())->text);
    }

    /** Deepgram reports float seconds; the rest of the pipeline speaks integer milliseconds. */
    public function testWordTimingsAreConvertedFromSecondsToMilliseconds(): void
    {
        $tokens = $this->recognise($this->client())->tokens;

        $this->assertSame(0, $tokens[0]->startMs);
        $this->assertSame(320, $tokens[0]->endMs);
        $this->assertSame(1250, $tokens[2]->startMs);
    }

    /**
     * `punctuated_word` is what smart_format produced, so the token stream a reviewer edits matches the
     * transcript they were shown.
     */
    public function testThePunctuatedFormOfAWordIsPreferred(): void
    {
        $tokens = $this->recognise($this->client())->tokens;

        $this->assertSame(' please.', $tokens[3]->text);
    }

    public function testAWordWithNoPunctuatedFormFallsBackToThePlainOne(): void
    {
        $this->assertSame(' Two', $this->recognise($this->client())->tokens[0]->text);
    }

    /**
     * **The token contract.** Every token must begin with whitespace.
     *
     * {@see \App\AudioToText\Domain\Speaker\TranscriptToken} requires a word-initial token to carry a
     * leading space, and Deepgram emits whole words only — so every token this engine produces is
     * word-initial and every one must have it.
     *
     * This is the test that would have caught the shipped bug. Without the space the aligner reads
     * every token as a continuation of the previous word, which both runs the text together and
     * collapses an entire two-party call onto one speaker.
     * {@see DeepgramTokenContractTest} proves that consequence end to end.
     */
    public function testEveryTokenBeginsWithWhitespace(): void
    {
        $tokens = $this->recognise($this->client())->tokens;

        $this->assertNotSame([], $tokens);

        foreach ($tokens as $index => $token) {
            $this->assertMatchesRegularExpression(
                '/^\s/u',
                $token->text,
                sprintf('Token %d (%s) must start a new word.', $index, json_encode($token->text)),
            );
        }
    }

    /**
     * The space is a boundary marker, not padding — it must not change the words themselves.
     *
     * Trimmed, each token is exactly what Deepgram sent, punctuation included.
     */
    public function testTheAddedSpaceDoesNotAlterTheWord(): void
    {
        $tokens = $this->recognise($this->client())->tokens;

        $this->assertSame(
            ['Two', 'wonton', 'soups', 'please.'],
            array_map(static fn($token): string => trim($token->text), $tokens),
        );
    }

    /** Joining tokens verbatim reproduces the spoken line — that is what the aligner does. */
    public function testConcatenatingTokensVerbatimYieldsSpacedText(): void
    {
        $joined = '';
        foreach ($this->recognise($this->client())->tokens as $token) {
            $joined .= $token->text;
        }

        $this->assertSame('Two wonton soups please.', trim($joined));
    }

    /** The hints exist so these survive verbatim — "wonton", not "one ton". */
    public function testKeytermedTermsArePreservedExactlyAsTranscribed(): void
    {
        $this->assertStringContainsString('wonton', $this->recognise($this->client())->text);
    }

    public function testADetectedLanguageIsReported(): void
    {
        $this->assertSame('en', $this->recognise($this->client())->language);
    }

    /**
     * Null rather than an echo of the configured value.
     *
     * The badge on the page says what was *detected*; repeating the request back would make a setting
     * look like a finding.
     */
    public function testAnAbsentDetectedLanguageIsNullRatherThanInvented(): void
    {
        $body = $this->body();
        unset($body['results']['channels'][0]['detected_language']);

        $this->assertNull($this->recognise($this->client(200, $body))->language);
    }

    // ------------------------------------------------------------------ failure modes

    /**
     * @dataProvider refusals
     */
    public function testEveryRefusalBecomesASafeJobFailure(int $status, string $expectedFragment): void
    {
        try {
            $this->recognise($this->client($status, ['err_code' => 'NOPE']));
            $this->fail('HTTP ' . $status . ' must not be read as a transcript.');
        } catch (AudioTranscriptionException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
            $this->assertStringContainsString((string) $status, $e->technicalDetail());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusals(): array
    {
        return [
            'bad credentials' => [401, 'credentials'],
            'forbidden' => [403, 'credentials'],
            // The token budget lands here. Deepgram is the authority, and its verdict fails one job.
            'rejected request' => [400, 'could not transcribe'],
            'rate limited' => [429, 'rate-limiting'],
            'server error' => [500, 'temporarily unavailable'],
            'gateway error' => [503, 'temporarily unavailable'],
        ];
    }

    public function testATransportFailureIsDistinctFromARefusal(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('connection refused') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        try {
            $this->recognise($client);
            $this->fail('A transport failure must not be read as a transcript.');
        } catch (AudioTranscriptionException $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
            $this->assertStringContainsString('transport failure', $e->technicalDetail());
        }
    }

    public function testMalformedJsonIsAFailureNotAnEmptyTranscript(): void
    {
        $client = $this->clientReturning(new Response(200, [], '{not json'));

        $this->expectException(AudioTranscriptionException::class);
        $this->expectExceptionMessageMatches('/could not read/i');

        $this->recognise($client);
    }

    public function testAResponseWithoutChannelsIsAFailure(): void
    {
        $this->expectException(AudioTranscriptionException::class);

        $this->recognise($this->client(200, ['results' => ['channels' => []]]));
    }

    public function testAnEmptyTranscriptIsReportedAsNoSpeech(): void
    {
        $body = $this->body();
        $body['results']['channels'][0]['alternatives'][0]['transcript'] = '   ';

        $this->expectException(AudioTranscriptionException::class);
        $this->expectExceptionMessageMatches('/No speech/i');

        $this->recognise($this->client(200, $body));
    }

    /**
     * Missing timings are an error, not an empty success.
     *
     * A transcript stored with no tokens would silently lose speaker separation while looking as though
     * it had worked — COMMON mode has nothing to align.
     */
    public function testATranscriptWithNoWordTimingsIsAFailure(): void
    {
        $body = $this->body();
        $body['results']['channels'][0]['alternatives'][0]['words'] = [];

        try {
            $this->recognise($this->client(200, $body));
            $this->fail('A transcript with no timings must not be stored as a success.');
        } catch (AudioTranscriptionException $e) {
            $this->assertStringContainsString('alignment is impossible', $e->technicalDetail());
        }
    }

    // ------------------------------------------------------------------ the key never leaks

    /**
     * **The secrecy test.** No failure path may put the key in a message a human or a log will see.
     *
     * Both halves of every exception are checked: `getMessage()` reaches the page and the
     * `error_message` column, and `technicalDetail()` reaches the log. Neither may carry it.
     */
    public function testTheApiKeyNeverAppearsInAnyFailureMessage(): void
    {
        $cases = [
            fn() => $this->recognise($this->client(401, ['err' => 'bad key ' . self::KEY])),
            fn() => $this->recognise($this->client(500, ['err' => self::KEY])),
            fn() => $this->recognise($this->clientReturning(new Response(200, [], self::KEY))),
            fn() => $this->engine($this->refusingClient(), apiKey: '')->assertReady(),
        ];

        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail('Case ' . $index . ' should have failed.');
            } catch (AudioTranscriptionException $e) {
                $this->assertStringNotContainsString(self::KEY, $e->getMessage(), 'case ' . $index);
                $this->assertStringNotContainsString(self::KEY, $e->technicalDetail(), 'case ' . $index);
                $this->assertStringNotContainsString(self::KEY, (string) $e, 'case ' . $index);
            }
        }
    }

    /** A settings object printed into a log or a var_dump must not spill the key either. */
    public function testTheKeyIsNotRecoverableFromTheSettingsObject(): void
    {
        $settings = AudioToTextSettingsFactory::create(deepgramApiKey: self::KEY);

        $this->assertStringNotContainsString(self::KEY, print_r($settings->deepgram, true));
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($settings->deepgram));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<string> $keyterms
     */
    private function recognise(
        ClientInterface $client,
        array $keyterms = [],
        string $model = 'nova-3',
        string $language = 'multi',
        bool $numerals = false,
    ): \App\AudioToText\Domain\Transcription\AudioTranscriptionResult {
        $request = new TranscriptionRequest($this->wavPath(), DeepgramKeyterms::fromList($keyterms));

        return $this->engine($client, model: $model, language: $language, numerals: $numerals)
            ->recognise($request);
    }

    private function engine(
        ClientInterface $client,
        string $apiKey = self::KEY,
        string $model = 'nova-3',
        string $language = 'multi',
        bool $numerals = false,
    ): DeepgramEngine {
        $psr7 = new HttpFactory();

        return new DeepgramEngine(
            AudioToTextSettingsFactory::create(
                deepgramApiKey: $apiKey,
                deepgramModel: $model,
                deepgramLanguage: $language,
                deepgramNumerals: $numerals,
            ),
            $client,
            $psr7,
            $psr7,
        );
    }

    /** A real file, because the engine opens it — nothing about this reaches the network. */
    private function wavPath(): string
    {
        if ($this->wav === null) {
            $this->wav = (string) tempnam(sys_get_temp_dir(), 'a2t-deepgram-');
            file_put_contents($this->wav, 'RIFF....WAVEfmt ');
        }

        return $this->wav;
    }

    /**
     * Captures the outgoing request so the **final URI** can be asserted.
     *
     * @param array<string, mixed>|null $body
     */
    private function client(int $status = 200, ?array $body = null): RecordingHttpClient
    {
        return $this->clientReturning(
            new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body ?? $this->body())),
        );
    }

    private function clientReturning(ResponseInterface $response): RecordingHttpClient
    {
        return new RecordingHttpClient($response);
    }

    /** Fails the test if it is called at all — the guard for "readiness never opens a socket". */
    private function refusingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('No HTTP request may be made while checking readiness.');
            }
        };
    }

    /**
     * One realistic Deepgram body: a mix of punctuated and plain words, and a detected language.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'results' => [
                'channels' => [
                    [
                        'detected_language' => 'en',
                        'alternatives' => [
                            [
                                'transcript' => 'Two wonton soups please.',
                                'words' => [
                                    ['word' => 'Two', 'start' => 0.0, 'end' => 0.32],
                                    ['word' => 'wonton', 'punctuated_word' => 'wonton', 'start' => 0.32, 'end' => 0.98],
                                    ['word' => 'soups', 'punctuated_word' => 'soups', 'start' => 1.25, 'end' => 1.6],
                                    ['word' => 'please', 'punctuated_word' => 'please.', 'start' => 1.6, 'end' => 2.0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
