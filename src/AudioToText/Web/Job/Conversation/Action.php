<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Conversation;

use App\AudioToText\Application\EffectiveConversationReader;
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
 * The conversation on its own (GET /audio-to-text/job/{publicId}/conversation).
 *
 * Everything the detail page shows *around* the conversation — the transcript, the two role columns,
 * the metadata, the downloads — is deliberately absent. This page is for reading the exchange, and the
 * detail page is one click away for everything else.
 *
 * It decides nothing about speakers. The reader and {@see ConversationView} are the same two objects
 * the detail page uses, called the same way, so reviewed-over-raw fallback, the publish gate,
 * NEEDS_REVIEW neutrality, human confirmation and the timing all arrive already settled. A second
 * interpretation of the same stored segments is exactly how one screen ends up disagreeing with
 * another about who was speaking.
 *
 * **No audio is touched.** Everything rendered here is read from columns the worker wrote.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private EffectiveConversationReader $conversations,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $effective = $this->conversations->for($job);

        // Nothing to read: still queued or processing, failed, or a completed job whose speakers were
        // never separated. Redirected rather than 404'd, because the detail page already explains each
        // of those cases — and because this is where the conversions list sends every View link, so a
        // dead end here would be reachable by clicking a perfectly ordinary row.
        if ($job->status !== JobStatus::COMPLETED || $effective->isEmpty()) {
            return $this->redirect->toRoute(AudioToTextRoute::JOB, ['publicId' => $job->publicId]);
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'job' => $job,
                'conversation' => ConversationView::from(
                    $job->speakerSeparationStatus,
                    $effective->utterances,
                    $job->speakerRoleConfidence,
                    $effective->hasSeparatedText(),
                    $effective->rolesConfirmed,
                ),
                'effective' => $effective,
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
