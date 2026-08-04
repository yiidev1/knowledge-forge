<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use DateTimeImmutable;

/**
 * A prior version of a user question, captured when the question was edited.
 *
 * {@see Message::$content} always holds the current text; each edit appends the text it replaced here, so
 * the full history is auditable. {@see $editedByType}/{@see $editedById} record which admin or agent made
 * the edit — the same typed identity used for conversation ownership.
 */
final readonly class MessageRevision
{
    public function __construct(
        public int $id,
        public int $messageId,
        public int $revisionNumber,
        public string $content,
        public ParticipantType $editedByType,
        public int $editedById,
        public DateTimeImmutable $createdAt,
    ) {}
}
