<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job;

use App\AudioToText\Application\ConversationPresenter;
use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One job's detail page.
 *
 * Authorization is the route middleware plus existence — every authorized administrator may view every
 * job. A missing id yields the shared 404 rather than a 403, so an id that does not exist and one that
 * does are indistinguishable from outside.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private AudioToTextSettings $settings,
        private AppTimeZone $appTimeZone,
        private EffectiveConversationReader $conversations,
        private RecordingVoiceReader $voices,
        private ConversationPresenter $presenter,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $effective = $this->conversations->for($job);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'job' => $job,
                // The view decides how much may be claimed about each speaker, from the separation
                // status rather than from the roles stored on the utterances. The template receives
                // labels it can print verbatim and makes no judgement of its own.
                // One decision, made in the application layer: this recording's own conversation, or
                // the one the call's mixed recording holds. See ConversationPresenter.
                'conversation' => $this->presenter->for($job, $effective, $this->voices->for($job)),
                // The agent/customer blocks read from the same object as the turns above them, so a
                // corrected attribution can never show in one and not the other.
                'effective' => $effective,
                // Only meaningful while the job is still waiting; null otherwise.
                'queuePosition' => $this->jobs->queuePositionOf($job->id),
                'pollSeconds' => $this->settings->transcription->pollSeconds(),
                'appTimeZone' => $this->appTimeZone,
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
