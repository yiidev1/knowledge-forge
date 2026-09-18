<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function http_build_query;
use function rawurlencode;
use function sprintf;

/**
 * ============================================================================================
 *  THE ONE UNRESOLVED THING IN THIS FEATURE. IT LIVES HERE AND NOWHERE ELSE.
 *
 *  How a caller or callee channel is requested from the external recording API is **not yet
 *  confirmed by the client.** Every other class in this directory treats a request as an opaque
 *  URL produced by this one method, so when the client supplies a working example, exactly one
 *  `match` arm below changes and nothing else in the feature is touched.
 *
 *  Do not spread this knowledge. If a second place ever starts building these URLs, the two will
 *  disagree and the disagreement will present as "the caller channel returned the mixed file",
 *  which looks like success.
 * ============================================================================================
 *
 * ## What IS confirmed
 *
 * The mixed recording, because it is the request the existing, working diagnostic tool already makes
 * and which the client uses in production today:
 *
 *   GET {base}/fetch/{callSessionId}?time={time}&company={company}&name={name}
 *
 * That shape is reproduced here byte for byte. It is deliberately **not** imported from
 * `App\Order58\Web\TestRecordingApis` — those files are frozen, and reaching into them would couple a
 * new tool to a working one that must not change.
 *
 * ## What is NOT confirmed
 *
 * The client has provided a **filename convention** — `22342359-caller.wav` — but the endpoint above
 * takes a call session id and three opaque parameters, with no obvious filename slot and no merchant id
 * at all. At least four readings fit the evidence:
 *
 *   A. `name` carries the filename       ?name=22342359-caller.wav
 *   B. `name` carries the channel        ?name=caller
 *   C. the path segment carries it       /fetch/22342359-caller
 *   D. a different endpoint entirely     unknown
 *
 * **A is implemented below as the provisional default**, because it is the only reading under which the
 * client's stated convention — a *filename* — is the thing actually transmitted. It is a placeholder,
 * not a conclusion. {@see RecordingChannel::liveRetrievalIsConfirmed()} reports caller and callee as
 * unconfirmed, and the page says so wherever either is selected.
 *
 * ## To resolve this
 *
 * Ask the client for one working URL for a caller or callee file. Then change {@see DEFAULT_CANDIDATE}
 * to the matching case and adjust that arm of {@see channelUrl()}. The three filename tests and the URL
 * construction tests will tell you immediately whether anything else moved.
 */
final readonly class ChannelRequestMapping
{
    /**
     * The mixed request is the one the working tool already makes, reproduced exactly.
     *
     * Kept as a constant rather than shared with the existing tool on purpose: that directory is frozen,
     * and a shared constant would make this file able to break it.
     */
    public const BASE_URL = 'https://order58.xrainbow.com/api/external/recording';

    /**
     * The reading used unless a caller says otherwise.
     *
     * **Change this one value when the client confirms the real format.** Nothing else in the feature
     * needs editing — every other class treats a request as an opaque URL this class produced.
     */
    public const DEFAULT_CANDIDATE = self::CANDIDATE_NAME_IS_FILENAME;

    /** `?name=22342359-caller.wav` — the client's convention sent as the `name` parameter. */
    public const CANDIDATE_NAME_IS_FILENAME = 'name-is-filename';

    /** `?name=caller` — the channel word rather than the filename. */
    public const CANDIDATE_NAME_IS_CHANNEL = 'name-is-channel';

    /** `/fetch/22342359-caller` — the suffix on the path segment. */
    public const CANDIDATE_ID_CARRIES_SUFFIX = 'id-carries-suffix';

    /**
     * @param string $candidate which reading to use. A constructor argument rather than a hard-coded
     *                          constant so the tests can drive **every** candidate and prove that
     *                          switching really is a one-value change — which is the whole claim this
     *                          class makes. Production takes the default.
     */
    public function __construct(
        private string $candidate = self::DEFAULT_CANDIDATE,
        private string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * The URL to request, for any channel.
     *
     * Every segment and parameter is encoded rather than concatenated, so a value carrying `/`, `&` or
     * `?` cannot reshape the URL — the same rule the existing tool follows.
     *
     * @param string $recordingId already validated as digits-only by {@see ChannelRecordingRequest}
     */
    public function urlFor(ChannelRecordingRequest $request, RecordingChannel $channel): string
    {
        return $channel === RecordingChannel::Mixed
            ? $this->mixedUrl($request)
            : $this->channelUrl($request, $channel);
    }

    /**
     * CONFIRMED. The exact request the existing production tool makes.
     *
     * The `name` parameter's meaning is undocumented on the provider's side — the working tool defaults
     * it to `test` — so it is passed through from the form rather than invented here.
     */
    private function mixedUrl(ChannelRecordingRequest $request): string
    {
        return sprintf(
            '%s/fetch/%s?%s',
            $this->baseUrl,
            rawurlencode($request->recordingId),
            http_build_query([
                'time' => $request->time,
                'company' => $request->company,
                'name' => $request->name,
            ]),
        );
    }

    /**
     * **UNCONFIRMED.** The caller/callee request, under whichever reading this instance was given.
     *
     * This is the one method that changes when the client answers. Each arm is written out in full
     * rather than shared, so switching between them is a single-value edit and the difference between
     * the readings stays visible.
     */
    private function channelUrl(ChannelRecordingRequest $request, RecordingChannel $channel): string
    {
        return match ($this->candidate) {
            // A — the client's filename convention is what travels.
            self::CANDIDATE_NAME_IS_FILENAME => sprintf(
                '%s/fetch/%s?%s',
                $this->baseUrl,
                rawurlencode($request->recordingId),
                http_build_query([
                    'time' => $request->time,
                    'company' => $request->company,
                    'name' => $channel->fileNameFor($request->recordingId),
                ]),
            ),

            // B — the channel word rather than the filename.
            self::CANDIDATE_NAME_IS_CHANNEL => sprintf(
                '%s/fetch/%s?%s',
                $this->baseUrl,
                rawurlencode($request->recordingId),
                http_build_query([
                    'time' => $request->time,
                    'company' => $request->company,
                    'name' => $channel->value,
                ]),
            ),

            // C — the suffix rides on the path segment.
            self::CANDIDATE_ID_CARRIES_SUFFIX => sprintf(
                '%s/fetch/%s?%s',
                $this->baseUrl,
                rawurlencode($request->recordingId . '-' . $channel->value),
                http_build_query([
                    'time' => $request->time,
                    'company' => $request->company,
                    'name' => $request->name,
                ]),
            ),

            // D, or an unrecognised candidate. Refusing beats guessing: a wrong URL that answers 200 with the
            // mixed file is a worse outcome than no request at all, because it reads as success.
            default => throw new UnconfirmedChannelMapping($channel),
        };
    }

    /**
     * A one-line description of what is currently being sent, for the page to show.
     *
     * An operator testing an unconfirmed endpoint needs to see which reading produced the result they
     * are looking at, or a 404 tells them nothing they can act on.
     */
    public function describeCandidate(): string
    {
        return match ($this->candidate) {
            self::CANDIDATE_NAME_IS_FILENAME
                => 'A — the channel filename is sent as the "name" query parameter.',
            self::CANDIDATE_NAME_IS_CHANNEL
                => 'B — the channel word ("caller" / "callee") is sent as the "name" query parameter.',
            self::CANDIDATE_ID_CARRIES_SUFFIX
                => 'C — the channel suffix is appended to the recording id in the URL path.',
            default => 'Unrecognised candidate — caller and callee requests will be refused.',
        };
    }
}
