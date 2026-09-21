<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function sprintf;

/**
 * Which of the three recordings a separated call produces.
 *
 * The client generates three files per recording. For recording `22342359`:
 *
 *   22342359.wav          the mixed recording, both parties
 *   22342359-caller.wav   the caller's channel
 *   22342359-callee.wav   the callee's channel
 *
 * ## Caller and callee are NOT mapped to Customer and Agent
 *
 * Deliberately, and this matters. Nothing in this application knows the direction of an Order58 call —
 * a search of the entire codebase for "callee" returns no results outside this directory, and there is
 * no stored call-direction field anywhere. Labelling the caller "Customer" would be a guess presented as
 * a fact, and the whole point of a diagnostic tool is that it reports what it was given.
 *
 * So the page says Mixed, Caller and Callee, exactly as the provider names them. A mapping can be added
 * later, once there is something to derive it from.
 *
 * ## The filename convention lives here and nowhere else
 *
 * {@see fileNameFor()} is the single definition. It is a naming convention supplied by the client and is
 * not this application's to reinterpret — no extra extension, no parameters appended, no normalisation.
 *
 * Note what this class does **not** decide: how that filename (or the channel) is turned into an HTTP
 * request. That is unresolved and isolated in {@see ChannelRequestMapping}.
 */
enum RecordingChannel: string
{
    case Mixed = 'mixed';
    case Caller = 'caller';
    case Callee = 'callee';

    public function label(): string
    {
        return match ($this) {
            self::Mixed => 'Mixed',
            self::Caller => 'Caller',
            self::Callee => 'Callee',
        };
    }

    /**
     * What this channel's file is called, given an **already-validated** recording id.
     *
     * The caller is responsible for validating the id first — {@see ChannelRecordingRequest::validate()}
     * — because this method has no way to refuse, and a filename built from unvalidated input is exactly
     * how a traversal sequence reaches a path. A test asserts that every id this is called with in the
     * application has been through that validator.
     */
    public function fileNameFor(string $recordingId): string
    {
        return match ($this) {
            self::Mixed => sprintf('%s.wav', $recordingId),
            self::Caller => sprintf('%s-caller.wav', $recordingId),
            self::Callee => sprintf('%s-callee.wav', $recordingId),
        };
    }

    /**
     * The path segment this channel is requested by — the call session id, carrying the channel suffix.
     *
     * Confirmed by the client on 21 September 2026: `/fetch/22359279-caller`. The suffix belongs in the
     * **path**, not in `name`; a bare path returns the mixed recording with a 200, which reads as
     * success. Same validation contract as {@see fileNameFor()} — the id must already have been through
     * {@see ChannelRecordingRequest::validate()}.
     */
    public function requestSegmentFor(string $recordingId): string
    {
        return match ($this) {
            self::Mixed => $recordingId,
            self::Caller => sprintf('%s-caller', $recordingId),
            self::Callee => sprintf('%s-callee', $recordingId),
        };
    }

    /**
     * The `name` parameter: what the provider saves the download as.
     *
     * **No extension, deliberately.** The provider appends one, so passing `22359279-caller.wav` here
     * produces `22359279-caller.wav.wav` on disk. The client called this out explicitly, and it is the
     * reason this is a separate method from {@see fileNameFor()} rather than a reuse of it — the two
     * strings differ by exactly the extension, which is precisely the bug.
     */
    public function downloadNameFor(string $recordingId): string
    {
        return $this->requestSegmentFor($recordingId);
    }

    /**
     * Whether retrieving this channel from the live API is a confirmed, working path.
     *
     * **All three are, since 21 September 2026.** The client supplied a worked caller URL and the format
     * is reproduced in {@see ChannelRequestMapping}.
     *
     * Confirmed format is not the same as available data: separated channels exist only for the
     * merchants on the client's list, and a session id outside it has a mixed recording and nothing else.
     * {@see separatedChannelsNeedAListedMerchant()} is what the page says about that.
     */
    public function liveRetrievalIsConfirmed(): bool
    {
        return true;
    }

    /**
     * Whether asking for this channel depends on the merchant being on the client's list.
     *
     * Every account has a mixed recording. Caller and callee are generated only for listed merchants, so
     * a well-formed request for an unlisted one still fails — and an operator reading that failure needs
     * to know it is a data question, not a URL question.
     */
    public function separatedChannelsNeedAListedMerchant(): bool
    {
        return $this !== self::Mixed;
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Mixed, self::Caller, self::Callee];
    }
}
