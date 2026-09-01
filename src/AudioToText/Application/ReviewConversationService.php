<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\Exception\ReviewConflict;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;

/**
 * Applies an administrator's corrections to a conversation, and records that they did.
 *
 * ## What is never written
 *
 * `transcript`, `speaker_segments`, `agent_text` and `customer_text`. Every correction lands in the
 * reviewed layer, so the machine's own output survives for audit no matter how much is corrected on top
 * of it: a recording transcribed as "Yes. For pikup" still says that in `transcript` after an
 * administrator has fixed the spelling for readers.
 *
 * ## Correcting is not confirming
 *
 * A call the pipeline refused to publish keeps showing Speaker 1 / Speaker 2 through any number of
 * structural corrections. Fixing a boundary says nothing about who was speaking, so promoting the
 * untouched guessed roles around it would be asserting something nobody checked. Only
 * {@see confirmRoles()} makes that claim, and it is recorded as its own auditable operation.
 *
 * That is also why the two role columns stay NULL until confirmation: the conversation view already
 * refuses to print role labels for a job with no aggregate text, so an unconfirmed reviewed
 * conversation renders neutrally through the existing gate, with nothing new to enforce.
 *
 * ## Every accepted operation is atomic and audited
 *
 * The revision is written first inside the transaction, then the conditional update. If the version has
 * moved on the update matches nothing, the conflict is thrown, and the revision rolls back with it — so
 * a refused save leaves no orphan audit row.
 */
final readonly class ReviewConversationService
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private SegmentRevisionRepositoryInterface $revisions,
        private SpeakerSegmentsDecoder $decoder,
        private TransactionRunnerInterface $transaction,
    ) {}

    public function moveToAgent(string $publicId, int $adminId, int $turnIndex, int $expectedVersion): void
    {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Move,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->moveTo($turnIndex, SpeakerRole::AGENT),
        );
    }

    public function moveToCustomer(string $publicId, int $adminId, int $turnIndex, int $expectedVersion): void
    {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Move,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->moveTo($turnIndex, SpeakerRole::CUSTOMER),
        );
    }

    /**
     * Reassign a selection — a whole turn, or words inside one — to the other speaker.
     *
     * One call, one transaction, one revision, whatever it takes internally: a partial selection is
     * one or two splits followed by the move, and a merge if the result lands beside a turn of the
     * same voice and role. Composing it here rather than in the browser means the client never holds a
     * turn index across a mutation — indices shift when a split adds a turn, and a turn has no id to
     * hold on to instead.
     *
     * Recorded as MOVE, which is what the administrator did. The revision holds the whole conversation
     * as it stood before, so the intermediate splits need no record of their own.
     *
     * @param string   $selection what the administrator highlighted, or the turn's whole text
     * @param int|null $hint      roughly where the selection began, used only to choose between
     *                            repeats of the same words
     */
    public function moveText(
        string $publicId,
        int $adminId,
        int $turnIndex,
        string $selection,
        SpeakerRole $role,
        ?int $hint,
        int $expectedVersion,
    ): void {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Move,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->moveTextTo($turnIndex, $selection, $role, $hint),
        );
    }

    public function split(string $publicId, int $adminId, int $turnIndex, int $offset, int $expectedVersion): void
    {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Split,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->splitAt($turnIndex, $offset),
        );
    }

    public function mergeWithPrevious(string $publicId, int $adminId, int $turnIndex, int $expectedVersion): void
    {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Merge,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->mergeWithPrevious($turnIndex),
        );
    }

    public function mergeWithNext(string $publicId, int $adminId, int $turnIndex, int $expectedVersion): void
    {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::Merge,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->mergeWithNext($turnIndex),
        );
    }

    public function editText(
        string $publicId,
        int $adminId,
        int $turnIndex,
        string $text,
        int $expectedVersion,
    ): void {
        $this->apply(
            $publicId,
            $adminId,
            $expectedVersion,
            ReviewOperation::EditText,
            static fn(ReviewedConversationTurns $turns): ReviewedConversationTurns
                => $turns->editText($turnIndex, $text),
        );
    }

    /**
     * Record that an administrator has checked the whole conversation and stands behind the roles.
     *
     * Only this publishes the two role columns, and therefore only this lets the page print
     * Agent/Customer for a call the machine would not commit to.
     *
     * Both roles must carry text. A confirmation is a claim that this recording is an agent talking to
     * a customer; a thread sitting entirely on one side does not support that claim yet.
     *
     * @throws ReviewRejected when the job cannot be reviewed, the roles are already confirmed, or one
     *                        of the two roles has no text
     * @throws ReviewConflict when somebody else corrected the conversation first
     */
    public function confirmRoles(string $publicId, int $adminId, int $expectedVersion): void
    {
        $job = $this->load($publicId);

        if ($job->rolesConfirmedAt !== null) {
            throw ReviewRejected::alreadyConfirmed();
        }

        $turns = $this->currentTurns($job);

        if ($turns->isEmpty()) {
            throw ReviewRejected::nothingToReview();
        }

        // Enforced here rather than only on the page, so no later caller can publish a one-sided split.
        if (!$turns->hasBothRoles()) {
            throw ReviewRejected::rolesIncomplete();
        }

        $prior = $turns->toJson();

        $this->transaction->run(function () use ($job, $adminId, $expectedVersion, $turns, $prior): void {
            $this->revisions->add($job->id, $prior, ReviewOperation::ConfirmRoles, $adminId);

            // Confirmation and the derived text are one statement: "roles are confirmed" and "aggregate
            // text exists" must never disagree, because the view's publish gate reads the second to
            // decide the first.
            $applied = $this->jobs->confirmRoles(
                $job->id,
                // The turns as confirmed, which on an otherwise uncorrected conversation are the
                // machine's own. Storing them is what gives the confirmation somewhere to live.
                $turns->toJson(),
                $turns->textFor(SpeakerRole::AGENT),
                $turns->textFor(SpeakerRole::CUSTOMER),
                $adminId,
                $expectedVersion,
            );

            if (!$applied) {
                throw ReviewConflict::versionMoved();
            }
        });
    }

    /**
     * Discard every correction, returning the job to the machine's own result.
     *
     * The confirmation goes with it: with no reviewed layer there is nothing left for it to be about.
     * The revert is itself recorded, so the history shows the corrections existed and were withdrawn
     * rather than appearing never to have happened.
     *
     * @throws ReviewRejected when there is nothing to revert
     * @throws ReviewConflict when somebody else corrected the conversation first
     */
    public function revert(string $publicId, int $adminId, int $expectedVersion): void
    {
        $job = $this->load($publicId);

        if (!$job->isReviewed()) {
            throw ReviewRejected::nothingToRevert();
        }

        $prior = (string) $job->reviewedSegmentsJson;

        $this->transaction->run(function () use ($job, $adminId, $expectedVersion, $prior): void {
            $this->revisions->add($job->id, $prior, ReviewOperation::Revert, $adminId);

            if (!$this->jobs->clearReview($job->id, $adminId, $expectedVersion)) {
                throw ReviewConflict::versionMoved();
            }
        });
    }

    /**
     * The shared path: load, apply, audit, save — atomically.
     *
     * @param callable(ReviewedConversationTurns): ReviewedConversationTurns $change
     *
     * @throws ReviewRejected when the correction itself is invalid
     * @throws ReviewConflict when the version has moved on
     */
    private function apply(
        string $publicId,
        int $adminId,
        int $expectedVersion,
        ReviewOperation $operation,
        callable $change,
    ): void {
        $job = $this->load($publicId);
        $current = $this->currentTurns($job);

        if ($current->isEmpty()) {
            throw ReviewRejected::nothingToReview();
        }

        // Validated before the transaction opens: an invalid correction should cost nothing and leave
        // no trace, and the domain throws rather than approximating.
        $updated = $change($current);

        // The state as it stood before this change. On a job's first correction that is a copy of the
        // machine's own segments, which is what makes every revision self-contained.
        $prior = $current->toJson();

        $this->transaction->run(function () use ($job, $adminId, $expectedVersion, $operation, $updated, $prior): void {
            $this->revisions->add($job->id, $prior, $operation, $adminId);

            $applied = $this->jobs->saveReview(
                $job->id,
                $updated->toJson(),
                // NULL until roles are confirmed. Structure can be corrected all day on a call whose
                // speakers were never established; publishing role text is a separate, explicit act.
                $job->rolesConfirmedAt === null ? null : $updated->textFor(SpeakerRole::AGENT),
                $job->rolesConfirmedAt === null ? null : $updated->textFor(SpeakerRole::CUSTOMER),
                $adminId,
                $expectedVersion,
            );

            if (!$applied) {
                throw ReviewConflict::versionMoved();
            }
        });
    }

    /**
     * The conversation as it currently stands: the reviewed layer if there is one, else the machine's.
     */
    private function currentTurns(TranscriptionJob $job): ReviewedConversationTurns
    {
        if ($job->isReviewed()) {
            return ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson);
        }

        return ReviewedConversationTurns::fromUtterances(
            $this->decoder->decode($job->speakerSegmentsJson),
        );
    }

    /**
     * @throws ReviewRejected when the job is unknown or not in a correctable state
     */
    private function load(string $publicId): TranscriptionJob
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            // Same wording as a job that exists but cannot be corrected: a caller turning this into a
            // 404 must not be able to reveal which of the two it was.
            throw ReviewRejected::notCompleted();
        }

        if ($job->status !== JobStatus::COMPLETED) {
            throw ReviewRejected::notCompleted();
        }

        return $job;
    }
}
