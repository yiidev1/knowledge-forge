<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\ChannelRecordingRequest;
use App\Integration\Order58Recording\ChannelRequestMapping;
use App\Integration\Order58Recording\RecordingChannel;
use App\Integration\Order58Recording\UnconfirmedChannelMapping;
use App\Integration\Order58Recording\WavSignature;
use Codeception\Test\Unit;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

use function array_unique;
use function pack;
use function str_repeat;
use function strlen;

/**
 * The separated-channel recording probe. **No test here reaches the network.**
 *
 * Two things carry this feature, and both are tested hard:
 *
 *  1. The **filename convention** is the client's, and this application does not get to reinterpret it.
 *  2. The **caller/callee request format is unconfirmed**, so the tests pin where that unknown lives
 *     rather than pretending it is settled. If somebody spreads the mapping to a second place, the
 *     isolation test in `RecordingChannelIsolationTest` fails.
 */
final class RecordingChannelTest extends Unit
{
    private const RECORDING_ID = '22342359';

    // ------------------------------------------------------------------ 1-3. filenames

    public function testTheMixedFilenameIsTheBareRecordingId(): void
    {
        $this->assertSame('22342359.wav', RecordingChannel::Mixed->fileNameFor(self::RECORDING_ID));
    }

    public function testTheCallerFilenameCarriesTheCallerSuffix(): void
    {
        $this->assertSame('22342359-caller.wav', RecordingChannel::Caller->fileNameFor(self::RECORDING_ID));
    }

    public function testTheCalleeFilenameCarriesTheCalleeSuffix(): void
    {
        $this->assertSame('22342359-callee.wav', RecordingChannel::Callee->fileNameFor(self::RECORDING_ID));
    }

    /** No extra extension, no appended parameters — the convention is the client's, verbatim. */
    public function testNothingIsAppendedToTheConvention(): void
    {
        foreach (RecordingChannel::all() as $channel) {
            $name = $channel->fileNameFor(self::RECORDING_ID);

            $this->assertStringStartsWith(self::RECORDING_ID, $name);
            $this->assertStringEndsWith('.wav', $name);
            $this->assertStringNotContainsString('?', $name);
            $this->assertStringNotContainsString('&', $name);
        }
    }

    /** The three names are distinct, or a wrong-channel response could never be noticed. */
    public function testTheThreeChannelsProduceThreeDifferentNames(): void
    {
        $names = [];
        foreach (RecordingChannel::all() as $channel) {
            $names[] = $channel->fileNameFor(self::RECORDING_ID);
        }

        $this->assertSame($names, array_unique($names));
    }

    // ------------------------------------------------------------------ 4. URL construction

    public function testTheMixedUrlIsTheConfirmedProvenRequestShape(): void
    {
        $url = (new ChannelRequestMapping())->urlFor($this->request(), RecordingChannel::Mixed);

        $this->assertStringStartsWith(
            'https://order58.xrainbow.com/api/external/recording/fetch/22342359?',
            $url,
        );
        $this->assertStringContainsString('time=2026-03-11', $url);
        $this->assertStringContainsString('company=SWCC', $url);
        $this->assertStringContainsString('name=test', $url);
    }

    /**
     * The caller URL, exactly as the client confirmed it on 21 September 2026.
     *
     * Asserted whole rather than by fragments: the two mistakes this replaced — the suffix left out of
     * the path, and an extension left in `name` — are both invisible unless the entire URL is compared.
     */
    public function testTheCallerUrlMatchesTheClientsConfirmedFormat(): void
    {
        $url = (new ChannelRequestMapping())->urlFor($this->request(), RecordingChannel::Caller);

        $this->assertSame(
            'https://order58.xrainbow.com/api/external/recording/fetch/22342359-caller'
                . '?time=2026-03-11&company=SWCC&name=22342359-caller',
            $url,
        );
    }

    public function testTheCalleeUrlMatchesTheClientsConfirmedFormat(): void
    {
        $url = (new ChannelRequestMapping())->urlFor($this->request(), RecordingChannel::Callee);

        $this->assertSame(
            'https://order58.xrainbow.com/api/external/recording/fetch/22342359-callee'
                . '?time=2026-03-11&company=SWCC&name=22342359-callee',
            $url,
        );
    }

    /** The client's own worked example, reproduced character for character. */
    public function testTheClientsWorkedExampleIsReproducedExactly(): void
    {
        $request = new ChannelRecordingRequest('22359279', '871', '2026-09-16', 'SWCC', 'ignored');
        $url = (new ChannelRequestMapping())->urlFor($request, RecordingChannel::Caller);

        $this->assertSame(
            'https://order58.xrainbow.com/api/external/recording/fetch/22359279-caller'
                . '?time=2026-09-16&company=SWCC&name=22359279-caller',
            $url,
        );
    }

    /**
     * **No extension in `name`.** The provider appends one, so a `.wav` here lands as `.wav.wav`.
     *
     * The regression is silent — the download succeeds, just with a wrong name — so it is pinned rather
     * than left to review.
     */
    public function testTheNameParameterCarriesNoFileExtension(): void
    {
        $mapping = new ChannelRequestMapping();

        foreach (RecordingChannel::all() as $channel) {
            $this->assertStringNotContainsString(
                '.wav',
                $mapping->urlFor($this->request(), $channel),
                $channel->value . ' must not send a file extension in any parameter.',
            );
        }
    }

    /** The free-text `name` field is not what addresses a channel file; the session id is. */
    public function testTheFormsNameFieldDoesNotReachAChannelUrl(): void
    {
        $request = new ChannelRecordingRequest('22342359', '871', '2026-03-11', 'SWCC', 'whatever-typed');
        $url = (new ChannelRequestMapping())->urlFor($request, RecordingChannel::Caller);

        $this->assertStringNotContainsString('whatever-typed', $url);
    }

    /** Caller and callee must never produce the same URL as mixed, or the tool cannot tell them apart. */
    public function testEachChannelProducesADistinctUrl(): void
    {
        $mapping = new ChannelRequestMapping();
        $urls = [];

        foreach (RecordingChannel::all() as $channel) {
            $urls[] = $mapping->urlFor($this->request(), $channel);
        }

        $this->assertSame($urls, array_unique($urls));
    }

    /** Values are encoded, so one carrying `&` or `?` cannot reshape the URL. */
    public function testQueryValuesAreEncodedRatherThanConcatenated(): void
    {
        $request = new ChannelRecordingRequest('22342359', '871', '2026-03-11', 'A&B', 'x?y');
        $url = (new ChannelRequestMapping())->urlFor($request, RecordingChannel::Mixed);

        $this->assertStringContainsString('company=A%26B', $url);
        $this->assertStringContainsString('name=x%3Fy', $url);
    }

    /**
     * **Switching readings is one value.** The claim the whole design rests on, so it is tested rather
     * than asserted in prose: every candidate produces its own URL shape, from the same one class.
     *
     * @dataProvider candidates
     */
    public function testEachCandidateReadingProducesItsOwnUrlShape(string $candidate, string $expected): void
    {
        $url = (new ChannelRequestMapping($candidate))->urlFor($this->request(), RecordingChannel::Caller);

        $this->assertStringContainsString($expected, $url);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function candidates(): array
    {
        return [
            'confirmed — id carries the suffix' => [ChannelRequestMapping::CANDIDATE_ID_CARRIES_SUFFIX, '/fetch/22342359-caller?'],
            'rejected — name is the filename' => [ChannelRequestMapping::CANDIDATE_NAME_IS_FILENAME, 'name=22342359-caller.wav'],
            'rejected — name is the channel' => [ChannelRequestMapping::CANDIDATE_NAME_IS_CHANNEL, 'name=caller'],
        ];
    }

    /**
     * An unrecognised reading sends nothing at all.
     *
     * Refusing beats guessing: a wrong URL that answers 200 with the MIXED recording would be reported
     * as the caller channel, which reads as success and gets believed.
     */
    public function testAnUnrecognisedCandidateRefusesToBuildAUrl(): void
    {
        $this->expectException(UnconfirmedChannelMapping::class);

        (new ChannelRequestMapping('some-unconfirmed-reading'))->urlFor($this->request(), RecordingChannel::Caller);
    }

    /** Mixed is unaffected by the unknown: it uses the confirmed shape whatever the candidate says. */
    public function testMixedStillWorksWithAnUnrecognisedCandidate(): void
    {
        $url = (new ChannelRequestMapping('some-unconfirmed-reading'))->urlFor($this->request(), RecordingChannel::Mixed);

        $this->assertStringContainsString('/fetch/22342359?', $url);
    }

    // ------------------------------------------------------------------ 5. no credential

    /**
     * The endpoint is gated by an IP allowlist, not a credential. Nothing must invent one.
     *
     * The source-level counterpart is in `RecordingChannelIsolationTest`; this checks the request that
     * actually goes on the wire.
     */
    public function testNoAuthorizationHeaderIsEverSent(): void
    {
        $handler = new MockHandler([new GuzzleResponse(200, [], $this->wav())]);
        $stack = HandlerStack::create($handler);

        $sent = null;
        $stack->push(static function (callable $next) use (&$sent) {
            return static function ($request, array $options) use ($next, &$sent) {
                $sent = $request;

                return $next($request, $options);
            };
        });

        $probe = new ChannelApiProbe(httpClient: new GuzzleClient(['handler' => $stack]));
        $probe->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertNotNull($sent);
        $this->assertFalse($sent->hasHeader('Authorization'));
        $this->assertFalse($sent->hasHeader('X-Forwarded-For'));
    }

    // ------------------------------------------------------------------ 6-12. responses

    public function testASuccessfulWavIsReportedAsSuccess(): void
    {
        $result = $this->probe(new GuzzleResponse(200, ['Content-Type' => 'application/octet-stream'], $this->wav()))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::OK, $result->diagnosis->kind);
        $this->assertTrue($result->wav->isWav);
        $this->assertTrue($result->isPlayable());
    }

    /** The provider's actual wording, detected from the body rather than assumed from the status. */
    public function testAnIpAllowlistRejectionIsIdentifiedSpecifically(): void
    {
        $result = $this->probe(new GuzzleResponse(
            403,
            ['Content-Type' => 'text/plain'],
            '403 Forbidden - IP not authorized: 203.0.113.9',
        ))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::IP_NOT_WHITELISTED, $result->diagnosis->kind);
        $this->assertStringContainsString('whitelisted', $result->diagnosis->advice);
    }

    /** A 403 that is not about the IP must not be mislabelled as an allowlist problem. */
    public function testAnUnrelatedForbiddenIsNotCalledAnAllowlistProblem(): void
    {
        $result = $this->probe(new GuzzleResponse(
            403,
            ['Content-Type' => 'text/plain'],
            'Forbidden: this recording belongs to another account',
        ))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::FORBIDDEN, $result->diagnosis->kind);
    }

    public function testNotFoundIsReportedWithTheUnconfirmedFormatCaveat(): void
    {
        $result = $this->probe(new GuzzleResponse(404, ['Content-Type' => 'text/plain'], 'Not Found'))
            ->inspect($this->request(), RecordingChannel::Caller);

        $this->assertSame(ChannelDiagnosis::NOT_FOUND, $result->diagnosis->kind);
        // A 404 on an unconfirmed channel is ambiguous, and saying so is the difference between
        // "the file is missing" and "we asked the wrong way".
        $this->assertStringContainsString('has not been confirmed', $result->diagnosis->advice);
    }

    public function testUnauthorizedIsDistinctFromForbidden(): void
    {
        $result = $this->probe(new GuzzleResponse(401, [], 'Unauthorized'))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::UNAUTHORIZED, $result->diagnosis->kind);
    }

    public function testAServerErrorIsReportedAsTheProvidersFault(): void
    {
        $result = $this->probe(new GuzzleResponse(503, [], 'upstream down'))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::SERVER_ERROR, $result->diagnosis->kind);
    }

    public function testATimeoutStatusIsReported(): void
    {
        $result = $this->probe(new GuzzleResponse(408, [], ''))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::TIMEOUT, $result->diagnosis->kind);
    }

    /** No HTTP response at all — DNS, TLS, refused connection, or a client-side timeout. */
    public function testAConnectionFailureIsClassifiedWithoutAStatus(): void
    {
        $this->assertSame(
            ChannelDiagnosis::CONNECTION_FAILED,
            ChannelDiagnosis::fromTransportFailure('cURL error 7: Failed to connect')->kind,
        );
        $this->assertSame(
            ChannelDiagnosis::TIMEOUT,
            ChannelDiagnosis::fromTransportFailure('cURL error 28: Operation timed out')->kind,
        );
    }

    public function testATransportFailurePropagatesRatherThanBeingSwallowed(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new GuzzleRequest('GET', 'https://example.test')),
        ]);
        $probe = new ChannelApiProbe(httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]));

        $this->expectException(ConnectException::class);

        $probe->inspect($this->request(), RecordingChannel::Mixed);
    }

    /** A 200 with nothing in it is a failure, not silence. */
    public function testAnEmptyBodyIsAFailure(): void
    {
        $result = $this->probe(new GuzzleResponse(200, ['Content-Type' => 'audio/wav'], ''))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::EMPTY_BODY, $result->diagnosis->kind);
        $this->assertFalse($result->isPlayable());
    }

    /** An HTML error page returned with HTTP 200 must never be offered as audio. */
    public function testAnErrorPageWithA200IsNotTreatedAsAudio(): void
    {
        $result = $this->probe(new GuzzleResponse(
            200,
            ['Content-Type' => 'text/html'],
            '<html><body>Gateway error</body></html>',
        ))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertSame(ChannelDiagnosis::NOT_AUDIO, $result->diagnosis->kind);
        $this->assertFalse($result->isPlayable());
        $this->assertNotNull($result->textPreview(), 'A non-audio body should be readable for diagnosis.');
    }

    // ------------------------------------------------------------------ 13. WAV validation

    public function testAValidWavIsRecognised(): void
    {
        $wav = $this->wav();

        $this->assertTrue(WavSignature::inspect($wav, strlen($wav))->isWav);
    }

    public function testRiffWithoutWaveIsRejected(): void
    {
        // A RIFF container that is not audio — AVI and WebP both start this way.
        $avi = 'RIFF' . pack('V', 100) . 'AVI ' . str_repeat("\0", 100);

        $this->assertFalse(WavSignature::inspect($avi, strlen($avi))->isWav);
    }

    public function testTextIsNotMistakenForAWav(): void
    {
        $this->assertFalse(WavSignature::inspect('{"error":"nope"}', 16)->isWav);
    }

    /**
     * A truncated download has a perfectly valid header and no sound — the failure most likely to be
     * mistaken for success, so it is reported rather than accepted.
     */
    public function testATruncatedWavIsFlagged(): void
    {
        $wav = $this->wav();
        $verdict = WavSignature::inspect($wav, 100);

        $this->assertTrue($verdict->isWav);
        $this->assertTrue($verdict->looksTruncated);
    }

    /**
     * The live endpoint answers `application/octet-stream` for real audio, so the Content-Type must
     * never be the deciding factor.
     */
    public function testAnOctetStreamContentTypeDoesNotDisqualifyAValidWav(): void
    {
        $result = $this->probe(new GuzzleResponse(200, ['Content-Type' => 'application/octet-stream'], $this->wav()))
            ->inspect($this->request(), RecordingChannel::Mixed);

        $this->assertTrue($result->isPlayable());
    }

    // ------------------------------------------------------------------ 16-17. input refusal

    /**
     * @dataProvider invalidRecordingIds
     */
    public function testAnInvalidRecordingIdIsRefusedBeforeAnyRequestIsBuilt(string $recordingId): void
    {
        $error = ChannelRecordingRequest::validate($recordingId, '871', '2026-03-11', 'SWCC', 'test');

        $this->assertNotNull($error, 'Refused input must never reach a filename or a URL.');
        $this->assertStringContainsString('Recording ID', $error);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRecordingIds(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'spaced' => ['223 42359'],
            'too long' => [str_repeat('9', 21)],
            // Path traversal, in the shapes that actually get attempted.
            'traversal' => ['../../etc/passwd'],
            'traversal encoded' => ['..%2F..%2Fetc%2Fpasswd'],
            'traversal suffix' => ['22342359/../../../etc/passwd'],
            'absolute path' => ['/etc/passwd'],
            'windows path' => ['..\\..\\windows\\system32'],
            'nul byte' => ["22342359\0"],
            // The one a `$`-anchored pattern would wrongly accept, because `$` matches before a newline.
            'newline smuggling' => ["22342359\n../../etc/passwd"],
        ];
    }

    public function testAValidRecordingIdIsAccepted(): void
    {
        $this->assertNull(ChannelRecordingRequest::validate('22342359', '871', '2026-03-11', 'SWCC', 'test'));
    }

    public function testAnInvalidMerchantIdIsRefused(): void
    {
        $this->assertNotNull(ChannelRecordingRequest::validate('22342359', 'abc', '2026-03-11', 'SWCC', 'test'));
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        $this->assertNotNull(ChannelRecordingRequest::validate('22342359', '871', '2026-02-31', 'SWCC', 'test'));
    }

    // ------------------------------------------------------------------ the Time rule, field by field

    /**
     * The exact set the field-level validator has to agree with.
     *
     * Kept as one table rather than scattered assertions because the client-side copy of this rule is
     * written against the same list: a case added here without a matching case in
     * `assets/recording-channels/recording-channels.js` is how the two start disagreeing, and a
     * disagreement means the browser refuses something the server would have accepted, or waves
     * through something it would not.
     *
     * @dataProvider timeValues
     */
    public function testTheTimeRuleAcceptsRealCalendarDatesAndNothingElse(string $time, bool $valid): void
    {
        $this->assertSame(
            $valid,
            ChannelRecordingRequest::timeError($time) === null,
            $time === '' ? '(empty)' : $time,
        );

        // The field-level view and the whole-request view must never disagree about the same value.
        $error = ChannelRecordingRequest::validate('22342359', '871', $time, 'SWCC', 'test');
        $this->assertSame($valid, $error === null);

        if (!$valid) {
            $this->assertSame(ChannelRecordingRequest::TIME_FORMAT_MESSAGE, $error);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function timeValues(): array
    {
        return [
            'a real date' => ['2026-03-11', true],
            'first of january' => ['2026-01-01', true],
            'last of december' => ['2026-12-31', true],
            'leap day in a leap year' => ['2024-02-29', true],

            'empty' => ['', false],
            'spaces only' => ['   ', false],
            'text appended' => ['2026-03-11sdasdasd', false],
            'text prepended' => ['abc2026-03-11', false],
            'slashes' => ['2026/03/11', false],
            'day first' => ['03-11-2026', false],
            'unpadded month' => ['2026-3-11', false],
            'unpadded day' => ['2026-03-1', false],
            'month 13' => ['2026-13-01', false],
            'month 00' => ['2026-00-10', false],
            'february 30th' => ['2026-02-30', false],
            'february 31st' => ['2026-02-31', false],
            'leap day in a common year' => ['2025-02-29', false],
            // `$`-anchored patterns match before a trailing newline; this must not slip through.
            'newline smuggling' => ["2026-03-11\n../../etc/passwd", false],
        ];
    }

    /** The message has one definition, so the field and the Result card cannot word it differently. */
    public function testTheTimeMessageIsTheSameWhereverItIsReported(): void
    {
        $this->assertSame(
            ChannelRecordingRequest::TIME_FORMAT_MESSAGE,
            ChannelRecordingRequest::timeError('2026-02-31'),
        );
        $this->assertSame(
            ChannelRecordingRequest::TIME_FORMAT_MESSAGE,
            ChannelRecordingRequest::validate('22342359', '871', '2026-02-31', 'SWCC', 'test'),
        );
    }

    /** A refused date stops the request being built at all - nothing reaches the external API. */
    public function testAnInvalidTimeMeansNoUrlIsEverBuilt(): void
    {
        $this->assertNotNull(ChannelRecordingRequest::validate('22342359', '871', '2026-02-31', 'SWCC', 'test'));

        // The action returns on that non-null error before constructing a ChannelRecordingRequest, so
        // this is the whole of what "no external request" means at this level. The page-level proof -
        // that no Request URL is even printed - is in RecordingChannelsCest.
        $this->assertNull(ChannelRecordingRequest::timeError('2026-03-11'));
    }

    public function testAControlCharacterInFreeTextIsRefused(): void
    {
        $this->assertNotNull(ChannelRecordingRequest::validate('22342359', '871', '2026-03-11', "SW\nCC", 'test'));
    }

    // ------------------------------------------------------------------ the unconfirmed mapping

    /** Every format is confirmed; availability per merchant is the separate fact the page shows. */
    public function testEveryChannelFormatIsConfirmed(): void
    {
        foreach (RecordingChannel::all() as $channel) {
            $this->assertTrue($channel->liveRetrievalIsConfirmed());
        }

        $this->assertFalse(RecordingChannel::Mixed->separatedChannelsNeedAListedMerchant());
        $this->assertTrue(RecordingChannel::Caller->separatedChannelsNeedAListedMerchant());
        $this->assertTrue(RecordingChannel::Callee->separatedChannelsNeedAListedMerchant());
    }

    public function testTheConfiguredCandidateIsDescribedForTheOperator(): void
    {
        $description = (new ChannelRequestMapping())->describeCandidate();

        $this->assertNotSame('', $description);
        $this->assertStringContainsString('name', $description);
    }

    // ------------------------------------------------------------------ helpers

    private function probe(GuzzleResponse $response): ChannelApiProbe
    {
        return new ChannelApiProbe(
            httpClient: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$response]))]),
        );
    }

    private function request(): ChannelRecordingRequest
    {
        return new ChannelRecordingRequest(self::RECORDING_ID, '871', '2026-03-11', 'SWCC', 'test');
    }

    /** A minimal but genuinely valid RIFF/WAVE file. */
    private function wav(int $dataBytes = 2048): string
    {
        $data = str_repeat("\x01\x00", (int) ($dataBytes / 2));

        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }
}
