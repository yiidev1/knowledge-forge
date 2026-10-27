<?php

declare(strict_types=1);

namespace App\Order58\Domain\CallRecordings;

/**
 * The writes one order needs, worked out before any of them is made.
 *
 * Built by the catalog from a listing and the stored rows, then handed to the repository to apply in
 * one transaction — so an order's recordings are never half-updated, and a listing identical to what is
 * stored produces an empty change set and no statement at all.
 */
final class OrderRecordingChanges
{
    /**
     * @var array<array-key, list<NewRecording>> sessions not stored yet, by provider session id — an
     *      integer key at runtime, as PHP stores a numeric string key; read it back with a cast
     */
    public array $newSessions = [];

    /** @var array<int, list<NewRecording>> new links for sessions already stored, by session row id */
    public array $newRecordings = [];

    /** @var array<int, StoredRecording> stored recordings whose position or listing changed, by row id */
    public array $updates = [];

    public function isEmpty(): bool
    {
        return $this->newSessions === [] && $this->newRecordings === [] && $this->updates === [];
    }
}
