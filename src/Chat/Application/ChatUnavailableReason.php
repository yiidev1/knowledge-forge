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
enum ChatUnavailableReason
{
    case Available;
    case NotProvisioned;
    case Order58NotReady;
    case NoQualifyingDocument;

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
            self::NotProvisioned => 'This knowledge base is still being provisioned. Chat becomes available once it is ready.',
            self::Order58NotReady => 'Chat is unavailable until the store profile and at least one Knowledge Base document are ready.',
            self::NoQualifyingDocument => 'Chat is unavailable until at least one enabled Knowledge Base document has finished processing and is ready.',
        };
    }
}
