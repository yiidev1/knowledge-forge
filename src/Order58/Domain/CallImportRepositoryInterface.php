<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\Integration\Order58Recording\RecordingChannel;
use DateTimeImmutable;

/**
 * The durable record of which external calls have been asked for, and what came of each channel.
 *
 * Two writers and two readers share it: the page creates work and shows history, the worker claims work
 * and records outcomes. The methods are split along that line rather than by table, because the unit
 * that matters is "one channel's import" and it spans both tables.
 */
interface CallImportRepositoryInterface
{
    /**
     * Record one administrator's Sync click, returning its id.
     *
     * The options are written here, once, and every item of the batch reads them back — including
     * `$recordingCompany`, which is a snapshot rather than a reference. See
     * {@see \App\Order58\Application\RecordingCompanyResolver}.
     */
    public function createBatch(
        int $storeSourceId,
        string $triggeredBy,
        ?int $requestedByAdminId,
        string $provider,
        bool $generateAiAudio,
        string $recordingCompany,
        DateTimeImmutable $now,
    ): int;

    /**
     * Queue every channel of one call, skipping any already requested for this store.
     *
     * Returns how many rows were actually created, so the page can say "9 queued, 3 already imported"
     * instead of implying work that the unique key silently refused. A duplicate is **not** an error:
     * the whole point of the key is that pressing Sync twice is safe.
     *
     * @param list<RecordingChannel> $channels
     *
     * @return int rows created
     */
    public function queueCall(
        int $batchId,
        int $storeSourceId,
        string $callSessionId,
        string $callTimeRaw,
        string $callDate,
        ?string $orderId,
        array $channels,
        DateTimeImmutable $now,
    ): int;

    /**
     * What this store already knows about these calls, keyed by call session id.
     *
     * Used to fill the page's status column before anything is selected, so an administrator can see
     * which of today's calls are already in hand. Returns only the calls that have rows.
     *
     * @param list<string> $callSessionIds
     *
     * @return array<string, non-empty-list<Order58ImportStatus>>
     */
    public function statusesFor(int $storeSourceId, array $callSessionIds): array;

    /**
     * Take the oldest eligible item and mark it as being worked on.
     *
     * Returns null when there is nothing to do. The claim must be atomic against a second worker even
     * though a lock file already guarantees one — defence in depth, exactly as the transcription
     * worker's own claim is.
     */
    public function claimNext(DateTimeImmutable $now): ?CallImportItem;

    /** Terminal, successful: this channel became a conversation. */
    public function markImported(
        int $id,
        string $conversationPublicId,
        int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void;

    /**
     * Terminal, and not a failure: the provider has no such recording, or it is unusable as it stands.
     *
     * `$bytes`/`$durationSeconds` are recorded where they are known — for a too-large refusal they are
     * the evidence, and the reason the limits can be reviewed against real calls later.
     */
    public function markSettled(
        int $id,
        Order58ImportStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void;

    /** Back to pending, with the attempt counted and the next try held off until `$nextAttemptAt`. */
    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void;

    /**
     * Return an item a worker claimed but never settled, so a killed process does not strand it.
     *
     * @return int rows recovered
     */
    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int;

    /**
     * Ask for one failed item to be tried again, clearing its attempt count.
     *
     * Returns false when the row is not in a state a person may retry — which is every state but
     * `failed`, and is why the button is not offered elsewhere.
     */
    public function retry(int $id, DateTimeImmutable $now): bool;

    /**
     * The history table: the most recent calls, newest first, with every channel of each.
     *
     * Grouped by call rather than returned flat, because the page shows one row per call with a column
     * per channel — flattening here and regrouping in a template would put the grouping rule in a view.
     *
     * @return list<CallImportHistoryRow>
     */
    public function history(?int $storeSourceId, int $limit): array;

    public function findItem(int $id): ?CallImportItem;
}
