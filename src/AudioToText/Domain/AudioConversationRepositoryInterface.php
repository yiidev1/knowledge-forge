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
