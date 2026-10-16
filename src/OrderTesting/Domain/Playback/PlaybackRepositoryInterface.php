<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Playback;

/**
 * Where playback attempts and their events are kept.
 *
 * Two tables behind one port: a session per attempt, and the events it passed through. The split is
 * what lets a count be one indexed row per attempt while the audit trail keeps every transition — and
 * what lets a bare dropdown change exist as an event with no attempt at all.
 */
interface PlaybackRepositoryInterface
{
    /**
     * Open an attempt, or return the one this token already opened.
     *
     * Idempotent by `publicId`: a double click, a retried POST and a refreshed tab all carry the same
     * token and all resolve to one row. The returned session is the authoritative one — a caller that
     * gets back a session it did not create must act on that one rather than insert a second.
     */
    public function openSession(NewPlaybackSession $session): PlaybackSession;

    public function findSession(string $publicId): ?PlaybackSession;

    /**
     * Move a session on, and record the event that moved it, in one transaction.
     *
     * Returns the session as it now stands. A transition the status rules refuse leaves the row
     * untouched and records nothing: a late COMPLETED cannot revive a stopped session, and a second
     * one cannot count twice.
     */
    public function advance(
        PlaybackSession $session,
        PlaybackEventType $event,
        ?PlaybackStatus $status,
        ?int $messagePlayed,
        ?string $detail,
    ): PlaybackSession;

    /** Record a dropdown change that belongs to no attempt. */
    public function recordSelection(GapSelection $selection): void;

    /**
     * @param list<string> $jobPublicIds
     * @return list<PlaybackAgentSummary> one row per agent per recording, newest activity first
     */
    public function summaryFor(int $storeSourceId, ?int $sourceOrderId, array $jobPublicIds): array;

    /**
     * @param list<string> $jobPublicIds
     * @return list<PlaybackSession> newest first, for one agent on one order
     */
    public function historyFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
        int $limit,
        int $offset,
    ): array;

    /** @param list<string> $jobPublicIds */
    public function countHistoryFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
    ): int;

    /**
     * @param list<string> $jobPublicIds
     * @return list<GapSelectionRow> standalone dropdown changes, newest first
     */
    public function selectionsFor(
        int $storeSourceId,
        ?int $sourceOrderId,
        array $jobPublicIds,
        int $agentAdminId,
        int $limit,
    ): array;
}
