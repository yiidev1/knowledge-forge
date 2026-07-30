<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Archive;

use App\KnowledgeBase\Application\ArchiveKnowledgeBaseService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Archives (POST /knowledge-bases/{slug}/archive) or restores (…/restore) a knowledge base.
 *
 * One action handles both directions, chosen by the matched route name, so the reversible pair stays
 * together and the DI container needs only a single, stateless registration.
 */
final readonly class Action
{
    public function __construct(
        private ArchiveKnowledgeBaseService $service,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
        private CurrentRoute $currentRoute,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $restore = $this->currentRoute->getName() === 'kb.restore';

        if ($restore) {
            $this->service->restore($knowledgeBase->id());
            $this->flash->success('Knowledge base restored.');
        } else {
            $this->service->archive($knowledgeBase->id());
            $this->flash->success('Knowledge base archived.');
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
