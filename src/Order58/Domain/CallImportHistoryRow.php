<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\Integration\Order58Recording\RecordingChannel;
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
}
