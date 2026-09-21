<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function http_build_query;
use function rawurlencode;
use function sprintf;

/**
 * ============================================================================================
 *  EVERY URL THIS TOOL SENDS IS BUILT HERE, AND NOWHERE ELSE.
 *
 *  If a second place ever starts building these URLs, the two will disagree, and the disagreement
 *  will present as "the caller channel returned the mixed file" — which looks like success.
 * ============================================================================================
 *
 * ## The caller/callee format, confirmed by the client on 21 September 2026
 *
 * The channel is part of the **path segment**, appended to the call session id. `name` is only the
 * download display name:
 *
 *   GET {base}/fetch/{callSessionId}-caller?time={time}&company={company}&name={callSessionId}-caller
 *
 * The client's worked example, for KONG's KITCHEN:
 *
 *   https://order58.xrainbow.com/api/external/recording/fetch/22359279-caller
 *       ?time=2026-09-16&company=SWCC&name=22359279-caller
 *
 * Two details from that message are load-bearing and easy to get wrong:
 *
 *  1. **The session id in the path carries the suffix.** An earlier reading put the channel in `name`
 *     and left the path bare. That returns the *mixed* file with a 200, which is the failure mode this
 *     whole class exists to prevent — it reads as success.
 *  2. **`name` carries no extension.** The provider appends one, so `name=22359279-caller.wav` lands on
 *     disk as `22359279-caller.wav.wav`. {@see RecordingChannel::downloadNameFor()} is the single
 *     definition and deliberately has no `.wav` in it.
 *
 * ## Not every merchant has these files
 *
 * The client supplies separated channels only for the merchants on the list they provided. A session id
 * belonging to any other merchant has a mixed recording and no caller/callee files, so a channel request
 * for it fails however well-formed the URL is. That is a data-availability fact, not a URL bug, and the
 * page says so rather than leaving an operator to read a 404 as a mapping error.
 *
 * ## The mixed request
 *
 *   GET {base}/fetch/{callSessionId}?time={time}&company={company}&name={name}
 *
 * The request the existing, working diagnostic tool already makes, reproduced byte for byte. It is
 * deliberately **not** imported from `App\Order58\Web\TestRecordingApis` — those files are frozen, and
 * reaching into them would couple a new tool to a working one that must not change.
 *
 * ## The readings that were considered and rejected
 *
 * `$candidate` survives confirmation on purpose. Before the client answered, three readings fitted the
 * evidence; {@see CANDIDATE_ID_CARRIES_SUFFIX} turned out to be right. The other two are kept because a
 * test drives each of them, and those tests are what would catch a well-meaning edit that quietly put
 * the channel back into `name` — the exact mistake that produced a wrong file with a 200.
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

    /** **The format the client confirmed.** See the class docblock for their worked example. */
    public const DEFAULT_CANDIDATE = self::CANDIDATE_ID_CARRIES_SUFFIX;

    /** `/fetch/22359279-caller` — the suffix on the path segment. **Confirmed correct.** */
    public const CANDIDATE_ID_CARRIES_SUFFIX = 'id-carries-suffix';

    /** REJECTED. `?name=22359279-caller.wav` — returns the mixed file with a 200. */
    public const CANDIDATE_NAME_IS_FILENAME = 'name-is-filename';

    /** REJECTED. `?name=caller` — returns the mixed file with a 200. */
    public const CANDIDATE_NAME_IS_CHANNEL = 'name-is-channel';

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
     * CONFIRMED. The list of recent calls for an account.
     *
     * Reproduces the request the existing, working tool already makes — `{base}/{accountId}/latest-calls
     * ?limit={limit}` — so the two cannot drift. Nothing about this endpoint is in doubt; it is here only
     * because this class owns every URL this tool builds, which is what the isolation test enforces.
     *
     * @param int $accountId already validated as a positive integer by {@see LatestCallsRequest}
     */
    public function latestCallsUrl(int $accountId, int $limit): string
    {
        return sprintf(
            '%s/%d/latest-calls?%s',
            $this->baseUrl,
            $accountId,
            http_build_query(['limit' => $limit]),
        );
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
     * The caller/callee request.
     *
     * Each arm is written out in full rather than shared, so the difference between the confirmed format
     * and the two rejected readings stays visible at the point where it matters.
     */
    private function channelUrl(ChannelRecordingRequest $request, RecordingChannel $channel): string
    {
        return match ($this->candidate) {
            // CONFIRMED. The suffix rides on the path segment, and `name` is the display name the
            // provider saves the download as — with no extension, or it appends a second one.
            self::CANDIDATE_ID_CARRIES_SUFFIX => sprintf(
                '%s/fetch/%s?%s',
                $this->baseUrl,
                rawurlencode($channel->requestSegmentFor($request->recordingId)),
                http_build_query([
                    'time' => $request->time,
                    'company' => $request->company,
                    'name' => $channel->downloadNameFor($request->recordingId),
                ]),
            ),

            // REJECTED — the client's filename convention sent as `name`, path left bare.
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

            // REJECTED — the channel word rather than the filename, path left bare.
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

            // An unrecognised candidate. Refusing beats guessing: a wrong URL that answers 200 with the
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
            self::CANDIDATE_ID_CARRIES_SUFFIX
                => 'Confirmed format — the channel suffix is appended to the session id in the URL path, '
                    . 'and "name" is the download display name with no extension.',
            self::CANDIDATE_NAME_IS_FILENAME
                => 'REJECTED reading — the channel filename is sent as the "name" query parameter. '
                    . 'This returns the mixed recording.',
            self::CANDIDATE_NAME_IS_CHANNEL
                => 'REJECTED reading — the channel word is sent as the "name" query parameter. '
                    . 'This returns the mixed recording.',
            default => 'Unrecognised candidate — caller and callee requests will be refused.',
        };
    }
}
