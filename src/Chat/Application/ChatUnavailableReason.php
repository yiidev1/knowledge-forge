<?php

declare(strict_types=1);

namespace App\Chat\Application;

/**
 * Why chat is (un)available for a knowledge base, as decided by {@see ChatAvailabilityPolicy}.
 *
 * The message is user-safe and identical for admin and agent surfaces — it never names an internal sync
 * job, vector-store id, or processing failure. A refresh in progress is deliberately *not* a reason here:
 * the previous usable snapshot keeps chat available, so the policy returns {@see Available}.
 */
enum ChatUnavailableReason: string
{
    case Available = 'available';
    case SourceInactive = 'source_inactive';
    case NotProvisioned = 'not_provisioned';
    case Order58NotReady = 'order58_not_ready';
    case NoQualifyingDocument = 'no_qualifying_document';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }

    /**
     * The user-facing explanation to render when chat is unavailable, or null when it is available.
     */
    public function message(): ?string
    {
        return match ($this) {
            self::Available => null,
            self::SourceInactive => 'This store is inactive in Order58, so chat is unavailable until the source store becomes active again.',
            self::NotProvisioned => 'This knowledge base is still being provisioned. Chat becomes available once it is ready.',
            self::Order58NotReady => 'Chat is unavailable until this store has at least one ready knowledge document (Order58 knowledge, manual text, or an uploaded file). Store rules and the store profile on their own do not enable chat.',
            self::NoQualifyingDocument => 'Chat is unavailable until at least one enabled knowledge document has finished processing and is ready. Rule documents on their own do not enable chat.',
        };
    }

    /**
     * A short label for a card/badge (the longer {@see message()} is for the chat page). User-safe.
     */
    public function shortLabel(): ?string
    {
        return match ($this) {
            self::Available => null,
            self::SourceInactive => 'Source inactive',
            self::NotProvisioned => 'Vector store processing',
            self::Order58NotReady => 'No ready knowledge',
            self::NoQualifyingDocument => 'No ready knowledge',
        };
    }
}
