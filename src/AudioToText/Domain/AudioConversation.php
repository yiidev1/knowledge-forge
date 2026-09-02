<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use DateTimeImmutable;

use function array_map;
use function count;

/**
 * One upload, whatever it took to record it.
 *
 * A common upload is one mixed recording; a separate upload is a Customer file and an Agent file that
 * together describe one call. Both are one row here, so the store history, the counts and the status
 * all speak about conversations rather than about the jobs underneath — a paired upload is one
 * conversion to an administrator, and counting it as two would be wrong on every screen.
 *
 * The mode carries the provenance of the roles (see {@see ConversationMode}), which is what decides
 * whether the pipeline infers speakers or is simply told.
 */
final readonly class AudioConversation
{
    /**
     * @param list<AudioConversationChild> $children chronological, as created
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public ?int $storeSourceId,
        public ConversationMode $mode,
        public int $uploadedByAdminId,
        public ?string $uploadedByUsername,
        public DateTimeImmutable $createdAt,
        public array $children,
    ) {}

    /**
     * The one state to show for this upload.
     *
     * Derived on every read rather than stored: a child's status changes in the worker, and a second
     * copy on the parent would be one more thing that can disagree with the truth.
     */
    public function status(): ConversationStatus
    {
        return ConversationStatus::fromChildren(array_map(
            static fn(AudioConversationChild $child): JobStatus => $child->status,
            $this->children,
        ));
    }

    public function childFor(SourceRole $role): ?AudioConversationChild
    {
        foreach ($this->children as $child) {
            if ($child->sourceRole === $role) {
                return $child;
            }
        }

        return null;
    }

    /** A common upload has exactly one child, which is the job every existing screen already handles. */
    public function singleChild(): ?AudioConversationChild
    {
        return count($this->children) === 1 ? $this->children[0] : null;
    }

    /**
     * The total length of everything recorded, or null when nothing has been measured.
     *
     * Summed rather than maximised: for a separate pair these are two recordings of the same call from
     * two sources, and the sum is what the machine has to transcribe.
     */
    public function totalDurationSeconds(): ?float
    {
        $total = null;

        foreach ($this->children as $child) {
            if ($child->durationSeconds !== null) {
                $total = ($total ?? 0.0) + $child->durationSeconds;
            }
        }

        return $total;
    }

    /**
     * Whether the shape of this conversation matches what its mode promises.
     *
     * COMMON is exactly one COMMON child; SEPARATE is exactly one CUSTOMER and one AGENT. The enqueue
     * writes parent and children in one transaction so this cannot drift, and the assertion exists to
     * make that a fact the tests check rather than a comment.
     */
    public function hasValidShape(): bool
    {
        $expected = $this->mode->childRoles();

        if (count($this->children) !== count($expected)) {
            return false;
        }

        foreach ($expected as $role) {
            if ($this->childFor($role) === null) {
                return false;
            }
        }

        return true;
    }
}
