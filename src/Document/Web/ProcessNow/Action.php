<?php

declare(strict_types=1);

namespace App\Document\Web\ProcessNow;

use App\Document\Application\ProcessNowService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Expedites an in-progress document (POST /knowledge-bases/{slug}/documents/{documentId}/process-now).
 *
 * Enqueue-only: it raises the document's priority and clears its backoff so the next worker run handles
 * it first. It never calls OpenAI. Scoped by knowledge base.
 */
final readonly class Action
{
    public function __construct(
        private ProcessNowService $processNowService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $this->processNowService->processNow($knowledgeBase->id(), $documentId);
        $this->flash->success('Document moved to the front of the queue.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
