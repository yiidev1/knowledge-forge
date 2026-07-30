<?php

declare(strict_types=1);

namespace App\Document\Web\Retry;

use App\Document\Application\RetryDocumentService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Retries a failed document (POST /knowledge-bases/{slug}/documents/{documentId}/retry).
 *
 * Enqueue-only: the service resets the document to queued and schedules any old remote files for
 * removal, then returns immediately — the worker does the reprocessing. Scoped by knowledge base.
 */
final readonly class Action
{
    public function __construct(
        private RetryDocumentService $retryService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $this->retryService->retry($knowledgeBase->id(), $documentId);
        $this->flash->success('Document queued for another attempt.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
