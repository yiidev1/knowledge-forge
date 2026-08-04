<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * Persistence for the audit trail of edited questions.
 */
interface MessageRevisionRepositoryInterface
{
    /**
     * Appends the prior content of a message as a new revision. The revision number is supplied by the
     * caller (derived from the message's edit_count under the optimistic lock) so it is race-free against
     * the (message_id, revision_number) unique key.
     *
     * @return int The new revision row id.
     */
    public function add(
        int $messageId,
        int $revisionNumber,
        string $priorContent,
        ChatParticipant $editor,
        DateTimeImmutable $now,
    ): int;

    /**
     * Audit accessor: all revisions for a message, oldest revision first.
     *
     * @return list<MessageRevision>
     */
    public function findByMessage(int $messageId): array;
}
