<?php

declare(strict_types=1);

namespace App\Document\Web\Delete;

use App\Document\Application\DeleteDocumentService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Deletes a document (POST /knowledge-bases/{slug}/documents/{documentId}/delete).
 *
 * Scoped by knowledge base: the service resolves the document only within this base, so a document id
 * from another base yields a 404 rather than a cross-base deletion.
 */
final readonly class Action
{
    public function __construct(
        private DeleteDocumentService $deleteService,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $this->deleteService->delete($knowledgeBase->id(), $documentId);
        $this->flash->success('Document removed.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
