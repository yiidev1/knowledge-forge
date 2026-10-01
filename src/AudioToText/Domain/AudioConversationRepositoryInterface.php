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
