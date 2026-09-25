<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use function array_slice;
use function dirname;
use function is_file;
use function preg_match;
use function sprintf;
use function strlen;
use function substr;

/**
 * Serves the three sample recordings from this repository, for local work only.
 *
 * **Development and test only.** Every entry point checks {@see FixtureAvailability} first; this class
 * additionally refuses on its own, so a future caller that forgets the gate still cannot reach a fixture
 * from production. Two independent refusals for one rule, because the cost of getting it wrong is a
 * production page serving test audio as if it were a customer's call.
 *
 * ## Why not just let the live call fail
 *
 * Because a 403 from the allowlist stops the request before any of this tool's own logic runs — the WAV
 * validation, the diagnosis, the streaming, the download naming. Fixtures exercise all of it now,
 * honestly labelled, instead of leaving it untested until the day the IP is whitelisted.
 *
 * ## The filenames are the contract
 *
 * `tests/_data/recording-channels/` holds exactly the three files the client described, named exactly as
 * they named them. The lookup goes through {@see RecordingChannel::fileNameFor()} — the same method the
 * live path uses — so a change to the naming convention breaks both together rather than leaving the
 * fixture silently answering for a name the API no longer uses.
 *
 * Nothing here is a real customer recording: the files are a fraction of a second of generated tone.
 */
final readonly class FixtureRecordingSource
{
    private const DIRECTORY = 'tests/_data/recording-channels';

    /** The recording id the sample files were generated for. */
    public const SAMPLE_RECORDING_ID = '22342359';

    /**
     * The absolute path of a fixture, or null when there is no such file.
     *
     * @throws UnconfirmedChannelMapping never — but see {@see assertPermitted()} for the refusal that
     *                                   does apply
     */
    public function pathFor(string $recordingId, RecordingChannel $channel): ?string
    {
        $this->assertPermitted();

        // Belt and braces. The id has already been validated by ChannelRecordingRequest, but this class
        // is the one that turns it into a filesystem path, so it re-checks rather than trusting a caller:
        // digits only makes `../`, absolute paths and separators structurally impossible.
        if (preg_match('/\A\d{1,20}\z/', $recordingId) !== 1) {
            return null;
        }

        $path = $this->directory() . '/' . $channel->fileNameFor($recordingId);

        return is_file($path) ? $path : null;
    }

    /**
     * Read a fixture, bounded, for the diagnostic view.
     *
     * @return array{0: string, 1: int}|null sample, total bytes — or null when the file is absent
     */
    public function read(string $recordingId, RecordingChannel $channel, int $sampleBytes = 8192): ?array
    {
        $path = $this->pathFor($recordingId, $channel);

        if ($path === null) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        return [substr($contents, 0, $sampleBytes), strlen($contents)];
    }

    /**
     * A canned Latest Calls response, for local work.
     *
     * The rows carry the client's sample recording id alongside two that have no fixture, so the page's
     * own handling of a selectable-but-unavailable call is exercised too rather than only the happy path.
     * The shape is exactly what the existing working tool reads from this endpoint — `callTime`,
     * `callSessionId`, `orderId` — and nothing is invented beyond it.
     */
    public function latestCalls(LatestCallsRequest $request): LatestCallsResult
    {
        $this->assertPermitted();

        $rows = [
            new CallSummary(self::SAMPLE_RECORDING_ID, '2026-03-11 14:23:05', '58-100234'),
            new CallSummary('16438291', '2026-03-10 09:41:12', '58-100233'),
            // No date this application can read: the Time field must be left alone for this one.
            new CallSummary('18794639', 'March 9, 2026', '58-100232'),
        ];

        $rows = array_slice($rows, 0, $request->limit);

        return new LatestCallsResult(
            url: sprintf('file://%s (fixture latest-calls for account %d)', self::DIRECTORY, $request->accountId),
            status: 200,
            reason: 'OK (fixture)',
            calls: $rows,
            diagnosis: ChannelDiagnosis::forJson(200, '[]'),
            rawBody: '',
        );
    }

    /** What the page shows in place of a URL, so nobody mistakes a fixture for a real request. */
    public function describe(string $recordingId, RecordingChannel $channel): string
    {
        return sprintf('file://%s/%s', self::DIRECTORY, $channel->fileNameFor($recordingId));
    }

    /**
     * The second, independent refusal.
     *
     * The entry points check {@see FixtureAvailability::isRequested()}; this makes the rule hold even if
     * a later caller forgets to. A rule enforced in one place is a rule waiting to be bypassed.
     */
    private function assertPermitted(): void
    {
        if (!FixtureAvailability::isPermitted()) {
            throw new FixturesNotPermitted();
        }
    }

    private function directory(): string
    {
        // src/Order58/Web/TestRecordingChannels -> project root
        return dirname(__DIR__, 3) . '/' . self::DIRECTORY;
    }
}
