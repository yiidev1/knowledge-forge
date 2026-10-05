<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

/**
 * Conversations, which is what the store-facing screens count and list.
 *
 * The job repository stays the technical view — one row per recording, which is what the queue and the
 * global conversions list are about. This one is the business view: a separate upload is one entry
 * here and two entries there, and that difference is the whole reason both exist.
 */
interface AudioConversationRepositoryInterface
{
    /**
     * Create the parent. The children are inserted by the caller inside the same transaction.
     *
     * @return int the new conversation's id, for the children to point at
     */
    public function create(
        string $publicId,
        ?int $storeSourceId,
        ConversationMode $mode,
        int $uploadedByAdminId,
        DateTimeImmutable $createdAt,
        /**
         * Whether the uploader ticked "generate clean AI audio after transcription".
         *
         * Defaulted so no existing caller changes, and because "not asked" and "declined" mean the same
         * thing here: nothing is generated and nothing is billed.
         */
        bool $generateAiAudio = false,
        /**
         * Which upload card the recording came through, for the store's history to print.
         *
         * Defaulted for the same reason as the flag above: an upload that named no card is recorded as
         * having named none, rather than being assigned one it did not come from.
         */
        ?RecordingType $recordingType = null,
        /** The order this upload belongs to, already validated, or null when none was given. */
        ?string $orderId = null,
        /**
         * The provider's call session id, or null when whatever created this upload did not know one.
         *
         * Only ever a value handed over by the importer. Nothing derives it, and nothing writes it from
         * a browser — see {@see AudioConversation::$callSessionId}.
         */
        ?string $callSessionId = null,
        /**
         * When the call happened, as the provider wrote it, or null.
         *
         * Stored verbatim and never parsed — see {@see AudioConversation::$callTimeRaw}.
         */
        ?string $callTimeRaw = null,
    ): int;

    public function findByPublicId(string $publicId): ?AudioConversation;

    /**
     * One store's uploads, newest first, with their children loaded.
     *
     * @return list<AudioConversation>
     */
    public function forStore(int $storeSourceId, int $limit, int $offset = 0): array;

    public function countForStore(int $storeSourceId): int;

    /**
     * Which store a conversation was uploaded against, by its internal id.
     *
     * Deliberately narrower than {@see findByPublicId}: a page that only needs somewhere to navigate
     * back to should not pay for the conversation's children, and a job holds the numeric parent id
     * rather than the public one. Null covers both "no such conversation" and "uploaded outside any
     * store" — neither has a store page, so neither needs telling apart.
     */
    public function storeSourceIdFor(int $conversationId): ?int;

    /**
     * The public id of a conversation, from the numeric one a job carries.
     *
     * Same trade as {@see storeSourceIdFor()}: an action that knows a job and needs to redirect to its
     * call's page should not load the conversation and its children to learn one string. Null means no
     * such conversation.
     */
    public function publicIdFor(int $conversationId): ?string;

    /**
     * What this upload said it was: Mixed, Caller or Callee.
     *
     * Null for every row that predates recording types, and for a legacy Customer + Agent pair whose
     * halves carry their identity in `source_role` instead. Read per conversation because the screens
     * that need it hold a job and not its upload — the same shape as the two lookups above.
     */
    public function recordingTypeFor(int $conversationId): ?RecordingType;

    /** Which call this conversation is a recording of, or null when nothing recorded that. */
    public function callSessionFor(int $conversationId): ?string;

    /**
     * The job of the one confirmed mixed recording of a call, or null when there is not exactly one.
     *
     * The question a derived view has to ask, asked in one place so that "exactly one" cannot be
     * answered differently by two callers. **Null is returned for none and for several alike**, because
     * both mean the same thing to a reader: this call has no single mixed conversation that speaks for
     * it, so nothing may be derived from one. Several is a real state — the same recording uploaded
     * twice produces it — and resolving it by picking the newest would be inventing an answer.
     *
     * Confirmed means an administrator stood behind the roles. A mixed conversation whose speakers were
     * never established says nothing about which side is the agent, so it cannot lend that to anybody.
     */
    public function confirmedMixedJobIdForCallSession(int $storeSourceId, string $callSessionId): ?int;

    /**
     * Every Caller and Callee recording of one call, as job ids grouped by the side they record.
     *
     * The combined projection's child lookup. It returns **all** of them rather than one per side,
     * because "how many are there" is the question that decides whether a combined conversation may be
     * assembled at all: two recordings of the same side is a real state — the same file imported twice,
     * or a replacement uploaded beside the original — and nothing in this application records which of
     * them speaks for the call. Handing back a single id would resolve that silently, and the two
     * halves of one sentence could then come from two different recordings of it.
     *
     * Keyed by the stored `recording_type` value, so a caller reads it with
     * {@see RecordingType::Caller}->value rather than by position. Mixed rows are excluded: this
     * answers what a mixed recording can borrow from, and it cannot borrow from itself. A side with no
     * recording is absent from the result rather than present and empty.
     *
     * Ordered by job id within each side, so a caller that reports several has a stable order to print
     * them in.
     *
     * @return array<string, list<int>>
     */
    public function channelJobIdsForCallSession(int $storeSourceId, string $callSessionId): array;

    /**
     * The same question for an upload that records no call session: the order is the group.
     *
     * Only the importer writes `call_session_id`, so every recording added by hand has none — and the
     * store page has always grouped those by order, which is how an operator builds a Customer + Agent
     * set for one call with the "+ Add audio" controls on a row. This is that grouping, asked of the
     * database rather than inferred.
     *
     * Deliberately the **fallback**, never the primary: an order can hold more than one call, so a
     * recording that knows which call it is of must be grouped by that and nothing else.
     *
     * @return array<string, list<int>>
     */
    public function channelJobIdsForOrder(int $storeSourceId, string $orderId): array;

    /** The order one conversation belongs to, or null when the upload named none. */
    public function orderIdFor(int $conversationId): ?string;

    /**
     * Conversations that record no call session yet, with the filename their recording was uploaded as.
     *
     * For `kf:audio:link-call-sessions` and nothing else. Only rows where the column is still NULL are
     * returned, which is what makes the backfill idempotent: a second run has nothing left to consider,
     * and a value written by the importer is never a candidate to be overwritten.
     *
     * @return list<array{id: int, storeSourceId: int|null, recordingType: string|null, filename: string|null}>
     */
    public function unlinkedForCallSessionBackfill(int $limit): array;

    /**
     * Record which call a conversation belongs to.
     *
     * Writes only where the column is still NULL, so a race with the importer cannot overwrite the
     * provider's own value with one read out of a filename.
     *
     * @return bool whether the row was still unlinked and has now been linked
     */
    public function recordCallSession(int $conversationId, string $callSessionId): bool;

    /**
     * Whether this upload asked for clean AI audio.
     *
     * Narrow on purpose, like the two above: the workers ask this once per completed recording, and
     * loading a conversation and its children to read one flag would be a query nobody needs. A missing
     * conversation answers false, which is also the right answer.
     */
    public function generatesAiAudio(int $conversationId): bool;

    /**
     * Remove parents that have no children left.
     *
     * Retention deletes expired jobs one at a time, and the two children of a pair can fall in
     * different passes, so this runs after the purge loop rather than trying to reason about which
     * delete was the last one. With the default indefinite retention nothing expires and this is a
     * no-op.
     *
     * @return int how many were removed
     */
    public function deleteChildless(): int;
}
