<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review;

use App\AudioToText\Domain\Speaker\ConversationSide;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\Speaker\SplitPoint;
use App\AudioToText\Domain\Speaker\TurnTiming;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use DateTimeImmutable;

/**
 * Everything the correction page shows, decided once.
 *
 * The page has to answer several questions that are easy to answer inconsistently — may these roles be
 * shown as fact, may this merge happen, may this conversation be confirmed — and answering any of them
 * twice is how two parts of one screen end up disagreeing. They are all settled here, from the same
 * objects the service will use when the administrator presses the button.
 */
final readonly class ReviewPageView
{
    /**
     * @param list<ReviewTurnView> $turns
     */
    private function __construct(
        public array $turns,
        /** Whether any correction has been made, as opposed to this being the machine's own result. */
        public bool $isReviewed,
        /** Whether Agent/Customer may be printed as fact. */
        public bool $rolesPublished,
        /**
         * When a person confirmed them, or null if nobody has.
         *
         * The timestamp rather than a flag: it is the same fact, it carries *when* at no extra cost,
         * and one nullable field cannot disagree with itself the way a bool beside a date can.
         */
        public ?DateTimeImmutable $confirmedAt,
        /** Who confirmed, read from the CONFIRM_ROLES revision — the audit trail is the only record. */
        public ?string $confirmedByUsername,
        public bool $canConfirm,
        /** Why confirmation is unavailable, or null when it is available or already done. */
        public ?string $confirmBlockedReason,
        /** The `review_count` every form on the page carries, and the service checks. */
        public int $version,
    ) {}

    public static function build(
        TranscriptionJob $job,
        ConversationView $conversation,
        ReviewedConversationTurns $turns,
        ?string $confirmedByUsername = null,
    ): self {
        $views = [];

        foreach ($turns->turns as $index => $turn) {
            // The label and side come from the same ConversationView the read-only page renders, so
            // the two screens cannot disagree about how much may be claimed.
            $display = $conversation->turns[$index] ?? null;

            $views[] = new ReviewTurnView(
                $index,
                $display?->label ?? '',
                $display?->confirmed ?? false,
                $turn->text,
                $turn->role,
                $display?->side ?? ConversationSide::Neutral,
                $display?->timing ?? TurnTiming::untimed(),
                $turn->approx,
                $turn->edited,
                $turns->mergeAvailability($index, MergeDirection::Previous),
                $turns->mergeAvailability($index, MergeDirection::Next),
                SplitPoint::forText($turn->text),
                self::wouldMerge($turns, $index, SpeakerRole::AGENT),
                self::wouldMerge($turns, $index, SpeakerRole::CUSTOMER),
            );
        }

        $confirmedAt = $job->rolesConfirmedAt;
        $hasBothRoles = $turns->hasBothRoles();

        return new self(
            $views,
            $job->isReviewed(),
            $conversation->rolesPublished,
            $confirmedAt,
            $confirmedAt === null ? null : $confirmedByUsername,
            // Already-published conversations need no confirmation, so the button is not offered there
            // either — there is nothing left for it to assert.
            $confirmedAt === null && !$conversation->rolesPublished && $hasBothRoles,
            self::blockedReason($confirmedAt !== null, $conversation->rolesPublished, $hasBothRoles),
            $job->reviewCount,
        );
    }

    /**
     * Whether moving this whole turn to `$role` would also join it to a neighbour.
     *
     * Run on the value object, which is immutable and pure, so nothing is written and the answer comes
     * from the same rule the service will apply when the button is pressed.
     */
    private static function wouldMerge(ReviewedConversationTurns $turns, int $index, SpeakerRole $role): bool
    {
        $turn = $turns->turns[$index] ?? null;

        if ($turn === null || $turn->role === $role) {
            return false;
        }

        $moved = $turns->moveTo($index, $role);

        return $moved->mergeAvailability($index, MergeDirection::Next)->isAllowed()
            || $moved->mergeAvailability($index, MergeDirection::Previous)->isAllowed();
    }

    private static function blockedReason(bool $confirmedByAdmin, bool $published, bool $hasBothRoles): ?string
    {
        if ($confirmedByAdmin || $published) {
            return null;
        }

        return $hasBothRoles
            ? null
            : 'Roles cannot be confirmed until at least one non-empty turn is assigned to both '
                . 'Agent and Customer.';
    }
}
