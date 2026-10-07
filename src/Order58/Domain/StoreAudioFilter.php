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

    /**
     * Parse the `audio=` query parameter, falling back to the surface's own landing state.
     *
     * The fallback is a parameter rather than a constant because the two pickers land differently: the
     * audio page is neutral (every store) and Order Testing opens on the stores that actually hold
     * recordings. Both read the same words off the wire; only what "unspecified" means differs, and
     * each caller says which it is. An unrecognised value is treated as unspecified rather than as
     * `All`, so a typo lands an operator on the page they would have got with no parameter at all.
     */
    public static function fromRequest(?string $value, self $default = self::All): self
    {
        return self::tryFrom($value ?? '') ?? $default;
    }

    public function label(): string
    {
        return match ($this) {
            self::All => 'All stores',
            self::WithAudio => 'Uploaded audio',
        };
    }
}
