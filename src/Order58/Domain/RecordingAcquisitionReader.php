<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\Integration\Order58Recording\RecordingChannel;
use App\Shared\Audio\RecordingAcquisition;
use App\Shared\Audio\RecordingAcquisitionState;

/**
 * Turns this module's stored import statuses into the words both pages report them in.
 *
 * ## Why the translation is here and not in the shared model
 *
 * `order58_call_imports.status` is this feature's own bookkeeping — `pending`, `fetching`, `too_large`
 * — and none of it is anybody else's business. {@see RecordingAcquisitionState} is the vocabulary two
 * modules share. Keeping the mapping at this boundary means the shared side never learns an Order58
 * storage value, and this side can add a status without the audio module hearing about it.
 *
 * ## The two mappings worth stating outright
 *
 * - `TooLarge` reports as **Failed**, not Unavailable. The provider had the recording; this server
 *   declined it. That is a fact about our limits, which somebody can act on, and hiding it among the
 *   merchants who simply do not record separate channels would bury it.
 * - A row that was requeued after a transient failure is `pending` again, and so reports as
 *   **Waiting** — which is true. It is waiting, and its backoff is not the reader's business.
 */
final readonly class RecordingAcquisitionReader
{
    /**
     * One call's channels, in the order they are shown, from the rows that exist for it.
     *
     * Channels with no row at all are omitted rather than invented: a call asked for before this
     * feature existed, or a partial requeue, should report on what it has rather than claim three.
     *
     * @param array<string, Order58ImportStatus> $statusByChannel storage channel value => status
     */
    public static function forCall(
        string $callSessionId,
        ?string $orderId,
        ?string $callTimeRaw,
        array $statusByChannel,
    ): ?RecordingAcquisition {
        $channels = [];

        foreach (RecordingChannel::all() as $channel) {
            $status = $statusByChannel[$channel->value] ?? null;

            if ($status !== null) {
                $channels[$channel->value] = self::state($status);
            }
        }

        if ($channels === []) {
            return null;
        }

        /** @var non-empty-array<string, RecordingAcquisitionState> $channels */
        return new RecordingAcquisition($callSessionId, $orderId, $callTimeRaw, $channels);
    }

    public static function state(Order58ImportStatus $status): RecordingAcquisitionState
    {
        return match ($status) {
            Order58ImportStatus::Pending => RecordingAcquisitionState::Waiting,
            Order58ImportStatus::Fetching => RecordingAcquisitionState::Downloading,
            Order58ImportStatus::Imported => RecordingAcquisitionState::Downloaded,
            Order58ImportStatus::NotAvailable => RecordingAcquisitionState::Unavailable,
            // Our refusal, not the provider's absence — see the class docblock.
            Order58ImportStatus::TooLarge, Order58ImportStatus::Failed => RecordingAcquisitionState::Failed,
        };
    }
}
