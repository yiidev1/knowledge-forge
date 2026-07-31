<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\Document\Application\Text\ManualTextService;
use App\Document\Application\Text\TextUpdateOutcome;
use App\Document\Domain\Exception\UploadException;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Saves an edit to a manual-text document (POST /knowledge-bases/{slug}/documents/{documentId}/edit).
 *
 * The service decides whether a re-index is needed: an edit that leaves the normalized content identical
 * only updates the title and never touches the vector store, so the flash message reflects what happened.
 * A missing or non-manual document resolves to a 404 through {@see \App\Document\Domain\Exception\DocumentNotFound}.
 */
final readonly class UpdateAction
{
    public function __construct(
        private ManualTextService $manualText,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $slug,
        #[RouteArgument]
        int $documentId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $knowledgeBase = $this->finder->bySlug($slug);
        $form = FormData::fromRequest($request);

        try {
            $outcome = $this->manualText->update(
                $documentId,
                $knowledgeBase->id(),
                $form->string('title'),
                $form->raw('content'),
            );
            $this->flash->success($outcome === TextUpdateOutcome::Reindexed
                ? 'Manual text saved and queued for re-indexing.'
                : 'Manual text saved. Content unchanged, so it was not re-indexed.');

            return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
        } catch (UploadException $e) {
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('kb.documents.edit.show', ['slug' => $slug, 'documentId' => $documentId]);
        }
    }
}
