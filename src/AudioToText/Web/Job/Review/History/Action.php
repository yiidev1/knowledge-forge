<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\History;

use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\ConversationHistoryBuilder;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\AudioToTextViews;
use App\AudioToText\Web\Job\JobPageGuard;
use App\AudioToText\Web\Job\Review\ReviewPageView;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * What was corrected, as the correction page draws it
 * (GET /audio-to-text/job/{publicId}/review/history).
 *
 * ## Why this returns markup where its neighbour returns data
 *
 * The Details dialog builds a bubble from JSON because a bubble is a shape this application has three
 * renderings of already. A revision is not: it is one partial, `_partial/review-history.php`, holding
 * the Before/After arrangement, the merge note, the wording of every summary and the escaping of six
 * revisions of somebody's transcript. Sending the events as JSON would mean writing that partial a
 * second time in JavaScript, which is the one thing this work is meant to stop.
 *
 * So the dialog fetches the partial's own output and the page renders it inline. Same file, same
 * words, same `Html::encode` on every historical turn.
 *
 * ## It is a reading
 *
 * Built from {@see ReviewPageView::build()}, exactly as the page and the fragment are, so whether a
 * message has history at all is settled once — in `TurnLineage`, from the audit trail — rather than
 * guessed at from whether a turn looks edited.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private EffectiveConversationReader $conversations,
        private SegmentRevisionRepositoryInterface $revisions,
        private ConversationHistoryBuilder $history,
        private AppTimeZone $appTimeZone,
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
        private RecordingVoiceReader $voices,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $effective = $this->conversations->for($job);

        // The gate the page and the fragment apply. A job with nothing to correct has no revisions
        // to read either, and answering 404 keeps "no such job" and "nothing to show" alike.
        if ($job->status !== JobStatus::COMPLETED || $effective->isEmpty()) {
            return $this->guard->notFound();
        }

        $turns = $job->isReviewed()
            ? ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson)
            : ReviewedConversationTurns::fromUtterances($effective->utterances);

        $revisions = $this->revisions->forJob($job->id);

        // Named at upload time: a Caller or Callee recording is one person's words, whatever the
        // diarizer found inside it, and nothing below may offer to re-decide that.
        $voice = $this->voices->for($job);

        $page = ReviewPageView::build(
            $job,
            ConversationView::from(
                $job->speakerSeparationStatus,
                $effective->utterances,
                $job->speakerRoleConfidence,
                $effective->hasSeparatedText(),
                $effective->rolesConfirmed,
                $voice,
            ),
            $turns,
            null,
            $this->history->build($revisions, $turns),
            $voice,
        );

        // `renderPartialAsString`: the partial's own output, with no layout wrapped round it. The
        // page renders the same file inside its layout; this is the same markup without one.
        $html = $this->viewRenderer->renderPartialAsString(AudioToTextViews::reviewHistory(), [
            'turns' => $page->turns,
            'appTimeZone' => $this->appTimeZone,
            'voice' => $page->voice,
        ]);

        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            // Somebody's words, six revisions deep: never cached, never sniffed.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write($html);

        return $response;
    }
}
