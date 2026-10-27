<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * Where one stored recording lives, found by `(store, order, recording)` together.
 *
 * Only the audio endpoint asks for this, and it never sends the URL to the browser: the URL carries a
 * customer phone number and is fetched server-side.
 */
final readonly class RecordingLocation
{
    public function __construct(
        public int $recordingId,
        public int $storeSourceId,
        public int $sourceOrderId,
        public string $callSessionId,
        public int $position,
        public string $url,
    ) {}

    /**
     * What a downloaded copy is called on the user's machine.
     *
     * Built from ids this page already prints — never from the provider's own `name=`, which contains
     * the customer's phone number.
     */
    public function downloadFileName(): string
    {
        return 'order-' . $this->sourceOrderId . '-call-' . $this->callSessionId
            . '-' . ($this->position + 1) . '.wav';
    }
}
