<?php

declare(strict_types=1);

namespace App\Document\Web\Reindex;

use App\Document\Application\ReindexDocumentService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Re-indexes a ready document (POST /knowledge-bases/{slug}/documents/{documentId}/reindex).
 *
 * Enqueue-only: the current vector-store files are scheduled for removal and the document is reset to
 * queued, so the worker rebuilds the index from scratch. Scoped by knowledge base.
 */
final readonly class Action
{
    public function __construct(
        private ReindexDocumentService $reindexService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $this->reindexService->reindex($knowledgeBase->id(), $documentId);
        $this->flash->success('Document queued for re-indexing.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
