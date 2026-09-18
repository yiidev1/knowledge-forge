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
     * Whether retrieving this channel from the live API is a confirmed, working path.
     *
     * **Mixed is. Caller and callee are not.** The existing, proven endpoint is addressed by call session
     * id plus three opaque query parameters, and the client has not yet supplied a working example URL
     * for a separated channel — so how the provider expects a caller or callee file to be asked for is
     * genuinely unknown. See {@see ChannelRequestMapping} for the candidate readings.
     *
     * This is surfaced on the page rather than hidden, because the dangerous failure is not a 404: it is
     * a request that quietly returns the **mixed** file while the page reports it as the caller channel.
     */
    public function liveRetrievalIsConfirmed(): bool
    {
        return $this === self::Mixed;
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
