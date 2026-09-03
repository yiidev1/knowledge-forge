<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\ReviewOperation;
use DateTimeImmutable;

use function count;

/**
 * One recorded correction, reduced to the messages it actually touched.
 *
 * A revision stores the whole conversation as it stood beforehand, which is the right thing to keep but
 * far too much to show: an administrator asking what happened to one message does not want twenty
 * unchanged ones alongside it. {@see ConversationDiff} narrows that snapshot to the run the operation
 * replaced, and this is the result.
 *
 * **Both sides are kept, and both may hold more than one turn.** A merge has two before and one after; a
 * split has one and two; moving a selection between neighbours has two and two. Rendering either side as
 * a single message would turn every one of those into something that looks like a text edit and reads as
 * a lie about what was done.
 */
final readonly class HistoryEvent
{
    /**
     * @param list<ReviewedTurn> $before the run as it stood, in order
     * @param list<ReviewedTurn> $after  what replaced it, in order
     */
    public function __construct(
        public int $revisionNumber,
        public ReviewOperation $operation,
        /** Null when the account has since been removed. */
        public ?string $editedByUsername,
        public DateTimeImmutable $createdAt,
        public array $before,
        public array $after,
    ) {}

    /**
     * Whether more than one message was involved on either side.
     *
     * The dialog leans on this: a one-to-one change can be shown as "before / after", while anything
     * else has to name every message it touched.
     */
    public function involvesSeveralTurns(): bool
    {
        return count($this->before) > 1 || count($this->after) > 1;
    }

    /**
     * What happened, in the words the correction page already uses.
     *
     * Derived from the shape as well as the operation, because `MOVE` covers two different corrections:
     * reassigning a whole turn, and moving a selection out of one. The stored operation cannot tell them
     * apart — the run's shape can.
     */
    public function summary(): string
    {
        return match (true) {
            $this->operation === ReviewOperation::EditText => 'Wording corrected',
            $this->operation === ReviewOperation::Split => 'Message split in two',
            $this->operation === ReviewOperation::Merge && count($this->after) === 1 => 'Messages joined',
            $this->operation === ReviewOperation::Merge => 'Text moved between two messages',
            $this->operation === ReviewOperation::Move && !$this->involvesSeveralTurns() => 'Speaker reassigned',
            $this->operation === ReviewOperation::Move => 'Text moved to the other speaker',
            default => $this->operation->label(),
        };
    }
}
