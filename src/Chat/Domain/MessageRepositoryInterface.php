<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for messages within a conversation.
 *
 * The live-thread and OpenAI-history readers ({@see findByConversation}, {@see findRecentByConversation},
 * {@see findBefore}, {@see findActiveBefore}) all exclude superseded answers — a replaced answer must never
 * reappear in the thread or be fed back to the model. Audit access to superseded rows is deliberately kept
 * separate via {@see findAllByConversationIncludingSuperseded}.
 */
interface MessageRepositoryInterface
{
    public function add(NewMessage $message, DateTimeImmutable $now): int;

    /**
     * Inserts an assistant answer that is linked to its question via {@see NewMessage::$replyToMessageId},
     * upholding the one-active-answer invariant.
     *
     * If a concurrent regeneration already inserted the active answer for the same question — the only
     * duplicate the `ux_messages_active_answer` unique index can raise — this returns that existing
     * answer's id instead of failing, so a retry/edit race converges on a single answer. Any other
     * integrity error propagates.
     *
     * @return int The active answer's id (freshly inserted, or the winning concurrent one).
     */
    public function insertActiveAnswer(NewMessage $answer, DateTimeImmutable $now): int;

    /**
     * @return list<Message> Oldest first — created_at ASC, id ASC. Excludes superseded answers.
     */
    public function findByConversation(int $conversationId): array;

    /**
     * Newest $limit messages, returned oldest-first for chronological display. Excludes superseded answers.
     *
     * @return list<Message>
     */
    public function findRecentByConversation(int $conversationId, int $limit): array;

    /**
     * Messages strictly before the cursor message, using a (created_at, id) tuple. Excludes superseded
     * answers. Returns up to $limit rows in created_at ASC, id ASC. Empty if the cursor is missing
     * or does not belong to the conversation (caller should 404).
     *
     * @return list<Message>|null null when the cursor message is not in this conversation
     */
    public function findBefore(int $conversationId, int $beforeMessageId, int $limit): ?array;

    /**
     * Active (non-superseded) turns strictly before the given message, oldest-first. This is the history
     * fed to the provider when regenerating the answer to an edited question: it excludes the edited
     * message itself, its superseded answer, and anything after it.
     *
     * @return list<Message>
     */
    public function findActiveBefore(int $conversationId, int $messageId): array;

    public function countByConversation(int $conversationId): int;

    /**
     * A single message by id, scoped to the conversation, or null. Not filtered by superseded state —
     * this is an exact-id lookup used to resolve an edit/regenerate target.
     */
    public function findByIdInConversation(int $messageId, int $conversationId): ?Message;

    /**
     * The id of the most recent user message in the conversation (created_at, id), or null if none.
     * Only this message is editable.
     */
    public function findLatestUserMessageId(int $conversationId): ?int;

    /**
     * Optimistic-locked update of a user question's text. Succeeds only when the row is a user message in
     * this conversation whose current edit_count equals $expectedEditCount; on success it stores the new
     * content, sets edited_at and increments edit_count.
     *
     * @return bool true when exactly one row was updated; false on a stale edit_count or a wrong target
     *              (the caller treats false as an edit conflict).
     */
    public function updateUserContent(
        int $messageId,
        int $conversationId,
        int $expectedEditCount,
        string $content,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Marks every currently-active assistant answer to the given user question as superseded. Idempotent:
     * a question with no active answer is left untouched.
     */
    public function supersedeAnswersFor(int $userMessageId, DateTimeImmutable $now): void;

    /**
     * Whether the conversation's latest user question has no active (non-superseded) answer. A conversation
     * in this state is mid-regeneration or its regeneration failed; a new question must be blocked until it
     * is resolved. False when the conversation has no user messages.
     */
    public function hasUnansweredLatestUserMessage(int $conversationId): bool;

    /**
     * Audit accessor: every message in the conversation including superseded answers, oldest-first. Never
     * used for the live thread or OpenAI history — only for support/audit read paths.
     *
     * @return list<Message>
     */
    public function findAllByConversationIncludingSuperseded(int $conversationId): array;
}
