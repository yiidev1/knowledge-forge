<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\Integration\Order58Recording\RecordingChannel;
use App\Shared\Audio\RecordingAcquisition;
use DateTimeImmutable;

/**
 * One call as the history table draws it: the call's own facts, and a cell per channel.
 *
 * The channel map is keyed by the channel's storage value and may be missing entries — a call queued
 * before a merchant's separated channels were known has only a mixed row, and an absent cell is drawn as
 * a dash rather than as a status. That is different from `Not available`, which means the provider was
 * asked and said no.
 */
final readonly class CallImportHistoryRow
{
    /**
     * @param array<string, CallImportItem> $channels keyed by {@see RecordingChannel::value}
     */
    public function __construct(
        public int $storeSourceId,
        public string $storeName,
        public string $callSessionId,
        public ?string $orderId,
        public string $callTimeRaw,
        public array $channels,
        public string $provider,
        public bool $generateAiAudio,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function channel(RecordingChannel $channel): ?CallImportItem
    {
        return $this->channels[$channel->value] ?? null;
    }

    /**
     * The call's single status, from the channels it actually has.
     *
     * Absent channels are not counted: a call with only a mixed row that imported is `Completed`, not
     * `Partial`, because nothing was asked for and refused.
     */
    public function outcome(): CallImportOutcome
    {
        $statuses = [];

        foreach ($this->channels as $item) {
            $statuses[] = $item->status;
        }

        return $statuses === []
            ? CallImportOutcome::Failed
            : CallImportOutcome::fromChannels($statuses);
    }

    /** Whether any channel of this call is in a state a person may ask to retry. */
    public function hasRetryable(): bool
    {
        foreach ($this->channels as $item) {
            if ($item->status->isRetryable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same call in the vocabulary both live pages use, so one row reads alike wherever it appears.
     *
     * Null only for a call with no channel rows at all, which the grouping cannot produce.
     */
    public function acquisition(): ?RecordingAcquisition
    {
        $statuses = [];

        foreach ($this->channels as $channel => $item) {
            $statuses[$channel] = $item->status;
        }

        return RecordingAcquisitionReader::forCall(
            $this->callSessionId,
            $this->orderId,
            $this->callTimeRaw,
            $statuses,
        );
    }

    /**
     * When a person asked for these recordings.
     *
     * The earliest of the channels, which in practice is all of them: a call's three rows are written in
     * one statement. Deliberately **not** called a sync time — nothing was synchronised, somebody
     * pressed a button, and naming it after a different operation would mislead anybody reading the
     * column to work out what happened when.
     */
    public function requestedAt(): ?DateTimeImmutable
    {
        $earliest = null;

        foreach ($this->channels as $item) {
            if ($item->createdAt !== null && ($earliest === null || $item->createdAt < $earliest)) {
                $earliest = $item->createdAt;
            }
        }

        return $earliest;
    }

    /**
     * When the acquisition finished — the **last** channel to settle.
     *
     * Channels finish at different moments, so this has to choose, and the last one is the only choice
     * that means "this call is done". The first would date the call from a recording that arrived while
     * two others were still outstanding.
     *
     * Null while anything is still outstanding, which is what makes it safe to render as a completion
     * time: a value here is a promise that there is nothing left to wait for.
     */
    public function downloadedAt(): ?DateTimeImmutable
    {
        $latest = null;

        foreach ($this->channels as $item) {
            if (!$item->status->isSettled()) {
                return null;
            }

            if ($item->completedAt !== null && ($latest === null || $item->completedAt > $latest)) {
                $latest = $item->completedAt;
            }
        }

        return $latest;
    }
}
