<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Conversion;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One logical conversion (GET /audio-to-text/conversion/{publicId}) — the page a row in a store's
 * history opens.
 *
 * ## Why it branches instead of rendering everything itself
 *
 * A **common** conversion has exactly one recording, and the existing job page, conversation page and
 * correction screens already do the right thing for it. So this action redirects there rather than
 * reimplementing any of it: one place to fix, one place that can be wrong, and every link and bookmark
 * that already points at a job keeps working.
 *
 * It redirects specifically to `/review`, the same destination the global conversions list's View
 * action uses, so "View" means one thing everywhere. That page is safe for every row: a job with
 * nothing to correct — still queued, failed, or never speaker-separated — redirects itself on to the
 * detail page, so this cannot land anyone at a dead end.
 *
 * A **separate** conversion has no such page, because it is not a turn-based conversation. Two files
 * recorded independently carry no synchronisation this application can trust — no shared clock, no
 * common start — so interleaving them into one thread would mean inventing an ordering. This page
 * therefore shows the two transcripts side by side, each labelled with the role the administrator
 * supplied, and offers neither the conversation view nor speaker correction: there are no turns to
 * order and no speakers to identify.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AudioConversationRepositoryInterface $conversations,
        private TranscriptionJobRepositoryInterface $jobs,
        private AudioStoreLookupInterface $stores,
        private AudioToTextSettings $settings,
        private Redirect $redirect,
        private AppTimeZone $appTimeZone,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $conversation = $this->conversations->findByPublicId($publicId);

        if ($conversation === null) {
            return $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        if ($conversation->mode === ConversationMode::Common) {
            $child = $conversation->singleChild();

            // A common conversation always has exactly one child. If a purge has already taken it,
            // there is nothing left to show and the conversions list is the honest destination.
            if ($child === null) {
                return $this->redirect->toRoute(AudioToTextRoute::JOBS);
            }

            return $this->redirect->toRoute(
                AudioToTextRoute::JOB_REVIEW,
                ['publicId' => $child->publicId],
            );
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'conversation' => $conversation,
                'store' => $conversation->storeSourceId === null
                    ? null
                    : $this->stores->findBySourceId($conversation->storeSourceId),
                'customer' => $this->jobFor($conversation, SourceRole::Customer),
                'agent' => $this->jobFor($conversation, SourceRole::Agent),
                'retentionHours' => $this->settings->transcription->retentionHours(),
                'appTimeZone' => $this->appTimeZone,
            ]);
    }

    /**
     * The full job behind one role's recording.
     *
     * The conversation's own children are a projection built for listing — filename, status, duration
     * — and carry no transcript, which is the one thing this page exists to show. Two lookups at most,
     * so the projection stays cheap for the history it was written for.
     */
    private function jobFor(AudioConversation $conversation, SourceRole $role): ?TranscriptionJob
    {
        $child = $conversation->childFor($role);

        return $child === null ? null : $this->jobs->findByPublicId($child->publicId);
    }
}
