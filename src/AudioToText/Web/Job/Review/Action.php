<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\JobPageGuard;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The speaker correction page (GET /audio-to-text/job/{publicId}/review).
 *
 * A page of its own rather than a mode on the job detail page. Corrections are Post/Redirect/Get, so
 * the mode has to survive a reload; a separate URL gets that without a query parameter to remember, and
 * leaves the read-only page exactly as it was.
 *
 * An unknown id is a 404 — "no such job" and "not available to you" must be indistinguishable from
 * outside. Anything else with nothing to correct is redirected to the detail page instead, because the
 * conversions list sends every View link here and an ordinary row must not reach a dead end.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private EffectiveConversationReader $conversations,
        private SegmentRevisionRepositoryInterface $revisions,
        private AudioConversationRepositoryInterface $conversationRepository,
        private AudioStoreLookupInterface $stores,
        private AppTimeZone $appTimeZone,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        // An unknown id stays a 404: "no such job" and "not available to you" have to be
        // indistinguishable from outside, which is the whole point of an unguessable public id.
        if ($job === null) {
            return $this->guard->notFound();
        }

        $effective = $this->conversations->for($job);

        // Nothing to correct — still queued or processing, failed, or a completed job whose speakers
        // were never separated. Redirected rather than 404'd, because this is where the conversions
        // list sends every View link: a dead end here would be reachable from an ordinary row, and
        // the detail page already explains each of those cases.
        if ($job->status !== JobStatus::COMPLETED || $effective->isEmpty()) {
            return $this->redirect->toRoute(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);
        }

        $conversation = ConversationView::from(
            $job->speakerSeparationStatus,
            $effective->utterances,
            $job->speakerRoleConfidence,
            $effective->hasSeparatedText(),
            $effective->rolesConfirmed,
        );

        // The same turns the service will load when a button is pressed, so what is offered on screen
        // and what is permitted on submit are computed from one object.
        $turns = $job->isReviewed()
            ? ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson)
            : ReviewedConversationTurns::fromUtterances($effective->utterances);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'job' => $job,
                'page' => ReviewPageView::build($job, $conversation, $turns, $this->confirmedBy($job->id)),
                // Page chrome, not review state, so it is passed beside ReviewPageView rather than into
                // it — the same shape the conversion page uses.
                'store' => $this->owningStore($job->conversationId),
                'appTimeZone' => $this->appTimeZone,
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * The store this job was uploaded against, or null when it was not uploaded against one.
     *
     * Derived from the job rather than from how the reader arrived: a job belongs to its store whether
     * it was opened from that store's page, from the global conversions list or from a bookmark, so
     * there is nothing about the journey worth carrying in a parameter.
     *
     * Two narrow reads, both skipped entirely for the store-less jobs that are still the majority. A
     * store removed from the mirror since the upload also lands here as null, and the page simply omits
     * the link — exactly what the conversion page already does.
     */
    private function owningStore(?int $conversationId): ?AudioStore
    {
        if ($conversationId === null) {
            return null;
        }

        $sourceId = $this->conversationRepository->storeSourceIdFor($conversationId);

        return $sourceId === null ? null : $this->stores->findBySourceId($sourceId);
    }

    /**
     * Who last confirmed the roles, from the audit trail.
     *
     * There is deliberately no `roles_confirmed_by` column — confirming is recorded as a CONFIRM_ROLES
     * revision, so the person and the moment are already stored once. Reading them back from there
     * keeps that the single record rather than adding a second that could disagree with it.
     */
    private function confirmedBy(int $jobId): ?string
    {
        $username = null;

        // Ascending, so the last match wins: a revert clears the confirmation, and confirming again
        // afterwards writes a second CONFIRM_ROLES. The most recent one is the one in force.
        foreach ($this->revisions->forJob($jobId) as $revision) {
            if ($revision->operation === ReviewOperation::ConfirmRoles) {
                $username = $revision->editedByUsername;
            }
        }

        return $username;
    }
}
