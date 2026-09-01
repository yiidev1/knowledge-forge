<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * What an administrator did to a conversation.
 *
 * Recorded on every revision so the audit trail says not just *what changed* but *what was intended* —
 * a diff between two snapshots shows a turn's text differing, but only the operation distinguishes
 * "the wording was corrected" from "a turn was split and one half kept its parent's words".
 *
 * The stored values are the CHECK constraint on `audio_segment_revisions.operation`; adding a case here
 * without adding it there will be rejected by the database, which is the intended order of events.
 */
enum ReviewOperation: string
{
    /** A whole turn reassigned to the other speaker. Text and timestamps untouched. */
    case Move = 'MOVE';

    /**
     * One turn cut into two.
     *
     * Token-level timestamps are not persisted, so the halves inherit the parent's range and are marked
     * approximate. Nothing is invented.
     */
    case Split = 'SPLIT';

    /** Two adjacent same-speaker turns joined; the range becomes min(start)…max(end). */
    case Merge = 'MERGE';

    /** A turn's wording corrected. Lives only in the reviewed layer — `transcript` is never rewritten. */
    case EditText = 'EDIT_TEXT';

    /**
     * An administrator confirmed who the speakers are, for the whole conversation.
     *
     * Deliberately separate from every structural operation. Fixing a turn boundary on a call the
     * machine refused to commit to must not promote the *untouched* guessed roles around it into
     * confirmed ones — the person has to say so, and this records that they did.
     */
    case ConfirmRoles = 'CONFIRM_ROLES';

    /** The reviewed layer discarded, returning the job to the machine's own result. */
    case Revert = 'REVERT';

    public function label(): string
    {
        return match ($this) {
            self::Move => 'Speaker changed',
            self::Split => 'Turn split',
            self::Merge => 'Turns merged',
            self::EditText => 'Text corrected',
            self::ConfirmRoles => 'Speaker roles confirmed',
            self::Revert => 'Reverted to original',
        };
    }

    /** Whether this operation can change the words a reader sees, as opposed to who said them. */
    public function altersText(): bool
    {
        return $this === self::EditText;
    }

    public static function fromStorage(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
