<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_scalar;
use function trim;

/**
 * Manual probe of the separated-channel recordings (GET /admin/order58/test-recording-channels).
 *
 * **A diagnostic page and nothing else.** It reads no database, writes no database, enqueues no job,
 * saves no file, and shares no code with the Order58 sync client or with Audio-to-Text. It makes at most
 * one outbound GET per page load and prints what came back.
 *
 * ## Separate from the existing recording test tool, on purpose
 *
 * `/admin/order58/test-recording-apis` is working, in use, and must not change. This is a new tool in a
 * new directory that copies its proven patterns — bounded reads, no credential, streamed download,
 * refusal to hand back a textual body as audio — rather than importing them. Reaching into that
 * directory to share code would couple a working tool to a new one for no behavioural gain.
 *
 * ## What is confirmed, and what is not
 *
 * Mixed retrieval uses the request shape the existing tool already makes successfully. **Caller and
 * callee do not have a confirmed request format** — the client has supplied a filename convention but
 * no working example URL — so that one unknown is isolated in {@see ChannelRequestMapping} and the page
 * says so wherever either channel is selected. The dangerous failure here is not a 404: it is a request
 * that quietly returns the mixed file while the page reports it as the caller channel.
 *
 * ## Errors are shown, not swallowed
 *
 * A non-2xx is rendered with its diagnosis, and a transport failure is caught here and reported rather
 * than reaching the global handler. Catching it locally keeps the change isolated: every other route
 * still fails the way it did.
 */
final readonly class Action
{
    // ---- Test defaults. The only place the pre-filled values are set. --------------------------------
    private const DEFAULT_RECORDING_ID = FixtureRecordingSource::SAMPLE_RECORDING_ID;
    private const DEFAULT_MERCHANT_ID = '871';
    private const DEFAULT_TIME = '2026-03-11';
    private const DEFAULT_COMPANY = 'SWCC';
    private const DEFAULT_NAME = 'test';
    // -------------------------------------------------------------------------------------------------

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ChannelApiProbe $probe,
        private FixtureRecordingSource $fixtures,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        // Echoed back exactly as typed, so a rejected value stays visible for correction.
        $recordingId = $this->text($params['recording_id'] ?? null, self::DEFAULT_RECORDING_ID);
        $merchantId = $this->text($params['merchant_id'] ?? null, self::DEFAULT_MERCHANT_ID);
        $time = $this->text($params['time'] ?? null, self::DEFAULT_TIME);
        $company = $this->text($params['company'] ?? null, self::DEFAULT_COMPANY);
        $name = $this->text($params['name'] ?? null, self::DEFAULT_NAME);

        $channel = RecordingChannel::fromStorage($this->text($params['channel'] ?? null)) ?? RecordingChannel::Mixed;
        $useFixtures = FixtureAvailability::isRequested($params[FixtureAvailability::QUERY_PARAMETER] ?? null);

        // A bare URL shows the form and runs nothing. Only an explicit submit probes anything.
        $submitted = ($params['submitted'] ?? null) === '1';

        $outcome = $submitted
            ? $this->run($recordingId, $merchantId, $time, $company, $name, $channel, $useFixtures)
            : null;

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'recordingId' => $recordingId,
                'merchantId' => $merchantId,
                'time' => $time,
                'company' => $company,
                'name' => $name,
                'channel' => $channel,
                'channels' => RecordingChannel::all(),
                // Only for an id that passed validation. `fileNameFor()` documents that it cannot
                // refuse, so handing it raw input would build a nonsense name like `../../etc/passwd.wav`
                // — harmless once escaped, but it would contradict the contract and show the operator a
                // filename this tool would never actually use.
                'fileName' => ChannelRecordingRequest::validate($recordingId, $merchantId, $time, $company, $name) === null
                    ? $channel->fileNameFor($recordingId)
                    : null,
                'useFixtures' => $useFixtures,
                'fixturesPermitted' => FixtureAvailability::isPermitted(),
                'sourceLabel' => FixtureAvailability::describe($useFixtures),
                'candidateDescription' => $this->probe->mapping()->describeCandidate(),
                'outcome' => $outcome,
            ]);
    }

    /**
     * Validate every field before anything is put on the wire, then fetch.
     *
     * @return array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}
     */
    private function run(
        string $recordingId,
        string $merchantId,
        string $time,
        string $company,
        string $name,
        RecordingChannel $channel,
        bool $useFixtures,
    ): array {
        // The same rules the download endpoint enforces, so the page can never offer a download link for
        // input that route would reject.
        $invalid = ChannelRecordingRequest::validate($recordingId, $merchantId, $time, $company, $name);

        if ($invalid !== null) {
            return $this->outcome(validationError: $invalid);
        }

        $request = new ChannelRecordingRequest($recordingId, $merchantId, $time, $company, $name);

        return $useFixtures
            ? $this->runFixture($request, $channel)
            : $this->runLive($request, $channel);
    }

    /**
     * @return array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}
     */
    private function runLive(ChannelRecordingRequest $request, RecordingChannel $channel): array
    {
        try {
            $url = $this->probe->urlFor($request, $channel);
        } catch (UnconfirmedChannelMapping $e) {
            // Nothing was sent. Refusing beats guessing — see UnconfirmedChannelMapping.
            return $this->outcome(diagnosis: ChannelDiagnosis::notConfigured($channel), failure: $e->getMessage());
        }

        try {
            $result = $this->probe->inspect($request, $channel);
        } catch (Throwable $e) {
            return $this->outcome(
                diagnosis: ChannelDiagnosis::fromTransportFailure($e->getMessage()),
                url: $url,
                // The class and message only. No trace: this page is reachable by any administrator, and
                // a trace is internal filesystem layout.
                failure: $e::class . ': ' . $e->getMessage(),
            );
        }

        return $this->outcome(result: $result, diagnosis: $result->diagnosis, url: $result->url);
    }

    /**
     * Answer from a local fixture, clearly labelled as such.
     *
     * Reaching here at all required {@see FixtureAvailability::isRequested()} to pass, which is false in
     * production and false unless the operator typed `?source=fixture` on this request.
     *
     * @return array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}
     */
    private function runFixture(ChannelRecordingRequest $request, RecordingChannel $channel): array
    {
        $read = $this->fixtures->read($request->recordingId, $channel);
        $where = $this->fixtures->describe($request->recordingId, $channel);

        if ($read === null) {
            return $this->outcome(
                diagnosis: ChannelDiagnosis::fromResponse(404, '', '', 0),
                url: $where,
                failure: 'No fixture exists for this recording id and channel. The sample files cover recording '
                    . FixtureRecordingSource::SAMPLE_RECORDING_ID . ' only.',
            );
        }

        [$sample, $bytes] = $read;

        $result = new ChannelProbeResult(
            url: $where,
            status: 200,
            reason: 'OK (fixture)',
            contentType: 'audio/wav',
            contentDisposition: '',
            contentLength: (string) $bytes,
            bytes: $bytes,
            sample: $sample,
            wav: WavSignature::inspect($sample, $bytes),
            diagnosis: ChannelDiagnosis::fromResponse(200, 'audio/wav', $sample, $bytes),
        );

        return $this->outcome(result: $result, diagnosis: $result->diagnosis, url: $where);
    }

    /**
     * @return array{validationError: ?string, result: ?ChannelProbeResult, diagnosis: ?ChannelDiagnosis, url: ?string, failure: ?string}
     */
    private function outcome(
        ?string $validationError = null,
        ?ChannelProbeResult $result = null,
        ?ChannelDiagnosis $diagnosis = null,
        ?string $url = null,
        ?string $failure = null,
    ): array {
        return [
            'validationError' => $validationError,
            'result' => $result,
            'diagnosis' => $diagnosis,
            'url' => $url,
            'failure' => $failure,
        ];
    }

    private function text(mixed $raw, string $default = ''): string
    {
        if (!is_scalar($raw)) {
            return $default;
        }

        $value = trim((string) $raw);

        return $value === '' ? $default : $value;
    }
}
