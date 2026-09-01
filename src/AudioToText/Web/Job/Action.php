<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Domain\Speaker\ConversationView;
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
                'conversation' => ConversationView::from(
                    $job->speakerSeparationStatus,
                    $effective->utterances,
                    $job->speakerRoleConfidence,
                    $effective->hasSeparatedText(),
                    // The second route to publication: an administrator who checked the conversation
                    // and stood behind the labels. Without this the machine's own status would be the
                    // only arbiter, and a confirmation would change nothing a reader could see.
                    $effective->rolesConfirmed,
                ),
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
