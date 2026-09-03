<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Original;

use App\AudioToText\Application\MachineConversationReader;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\JobPageGuard;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The machine's own transcription, as it was produced (GET /audio-to-text/job/{publicId}/original).
 *
 * A conversation can be corrected for as long as anyone keeps correcting it, and every one of those
 * corrections is an overlay: `transcript`, `speaker_segments`, `agent_text` and `customer_text` are
 * written once by the worker and by nothing afterwards. This page is the surface that makes that
 * property visible — open it beside `/review` and the two show the machine's version and the current
 * one side by side.
 *
 * It reads through {@see MachineConversationReader}, never the effective one, so no amount of
 * correcting can change what it prints. That is the whole feature.
 *
 * Two things are deliberately *not* claimed here. The page never publishes roles the machine itself
 * refused to publish — a later human confirmation applies to the reviewed layer, not this one — and it
 * offers no controls at all: no pencil, no drag handle, no merge, no confirm, no discard, no history.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private MachineConversationReader $machine,
        private AudioConversationRepositoryInterface $conversationRepository,
        private AudioStoreLookupInterface $stores,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        // An unknown id stays a 404, as on every other job page: "no such job" and "not available to
        // you" have to be indistinguishable from outside.
        if ($job === null) {
            return $this->guard->notFound();
        }

        $machine = $this->machine->for($job);

        // Nothing the machine produced to show — still queued or processing, failed, or a completed job
        // whose speakers were never separated, which includes every separate Customer + Agent recording
        // (those store no segments at all). The store page only offers this link for a completed common
        // conversion, so arriving here is a direct URL or a bookmark; the detail page explains each case.
        if ($job->status !== JobStatus::COMPLETED || $machine->isEmpty()) {
            return $this->redirect->toRoute(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'job' => $job,
                // Every argument is the machine's own, and the last one is false on purpose: see
                // MachineConversationReader for why a human confirmation may not publish this layer.
                'conversation' => ConversationView::from(
                    $job->speakerSeparationStatus,
                    $machine->utterances,
                    $job->speakerRoleConfidence,
                    $machine->hasSeparatedText(),
                    false,
                ),
                'store' => $this->owningStore($job->conversationId),
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * The store this job was uploaded against, or null when it was not uploaded against one.
     *
     * The same two narrow reads the correction page makes, and skipped entirely for a store-less job.
     */
    private function owningStore(?int $conversationId): ?AudioStore
    {
        if ($conversationId === null) {
            return null;
        }

        $sourceId = $this->conversationRepository->storeSourceIdFor($conversationId);

        return $sourceId === null ? null : $this->stores->findBySourceId($sourceId);
    }
}
