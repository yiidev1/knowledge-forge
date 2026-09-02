<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * The audio axis of the store-audio picker: every store, or only those something has been uploaded for.
 *
 * A separate axis from the source-active and knowledge-pipeline filters for the same reason those are
 * separate from each other — a store can be source-inactive and still hold a year of recordings, and
 * folding the two into one status would make both unanswerable.
 */
enum StoreAudioFilter: string
{
    case All = 'all';
    case WithAudio = 'with';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All stores',
            self::WithAudio => 'Uploaded audio',
        };
    }
}
