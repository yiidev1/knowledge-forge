<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Conversion\AiAudio;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_map;

/**
 * Clean AI training audio for one call (GET /audio-to-text/conversion/{publicId}/ai-audio).
 *
 * ## Why the conversion addresses it, and the job addresses everything under it
 *
 * A Customer + Agent pair is **one call**, and an administrator comparing the two sides wants them on
 * one screen — so the page is addressed by the conversion. A rendition, and the file it produces, belong
 * to a single recording, so the generate and file endpoints are addressed by the job. That keeps the
 * endpoint which streams bytes resolving straight to the thing it authorises, with no
 * conversation-to-child hop in the middle of a security-sensitive path.
 *
 * ## Read-only, and cheap
 *
 * Nothing here contacts a provider, spends anything or starts a process. It reads the transcript through
 * the same {@see \App\AudioToText\Application\EffectiveConversationReader} every other surface uses,
 * computes the digest of what *would* be spoken, and compares it with what was. Generation is a POST to
 * a different action and happens in a worker.
 *
 * The digest is recomputed on every render rather than cached. That is the whole staleness mechanism: it
 * has to reflect a correction the moment it is saved, and a cache lagging by even one request would show
 * the reassuring answer immediately after somebody changed something.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AudioConversationRepositoryInterface $conversations,
        private TranscriptionJobRepositoryInterface $jobs,
        private TtsRenditionRepositoryInterface $renditions,
        private AudioStoreLookupInterface $stores,
        private TtsGenerationService $generation,
        private TtsScriptBuilder $scripts,
        private AudioToTextSettings $settings,
        private Redirect $redirect,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $conversation = $this->conversations->findByPublicId($publicId);

        if ($conversation === null) {
            // A stale bookmark, almost always. The conversions list is where the administrator wanted to
            // be anyway, and is more use than a page explaining that an id no longer resolves.
            return $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $jobs = $this->load($conversation);

        if ($jobs === []) {
            return $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $page = AiAudioPage::build(
            $conversation,
            $conversation->storeSourceId === null
                ? null
                : $this->stores->findBySourceId($conversation->storeSourceId),
            $jobs,
            $this->renditions->forJobs(array_map(
                static fn(TranscriptionJob $job): int => $job->id,
                $jobs,
            )),
            $this->generation,
            $this->scripts,
            $this->settings->ttsIsUsable(),
            // Shown to an administrator, who is the person who would fix them. Named variables only —
            // no value, and certainly no credential.
            $this->settings->tts->problems(),
        );

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'page' => $page,
                'appTimeZone' => $this->appTimeZone,
            ]);
    }

    /**
     * The conversation's children, fully loaded.
     *
     * The child projection a conversation carries is built for listing and holds no transcript, which is
     * the one thing this page needs — so each child is fetched in full. At most two, and only on a page
     * somebody navigated to deliberately.
     *
     * @return list<TranscriptionJob>
     */
    private function load(AudioConversation $conversation): array
    {
        $jobs = [];

        foreach ($conversation->children as $child) {
            $job = $this->jobs->findByPublicId($child->publicId);

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }
}
