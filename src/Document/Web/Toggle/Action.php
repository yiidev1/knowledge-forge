<?php

declare(strict_types=1);

namespace App\Document\Web\Toggle;

use App\Document\Application\ToggleDocumentService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Enables or disables a document for retrieval
 * (POST /knowledge-bases/{slug}/documents/{documentId}/toggle).
 *
 * The desired state is submitted explicitly (a hidden `enabled` field), so the toggle is idempotent under a
 * double submission. Disabling hides the document from chat and schedules its vector-store removal; enabling
 * requeues it for re-indexing. Works for every source type, not just manual text.
 */
final readonly class Action
{
    public function __construct(
        private ToggleDocumentService $toggle,
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
        $enabled = FormData::fromRequest($request)->string('enabled') === '1';

        $this->toggle->setEnabled($documentId, $knowledgeBase->id(), $enabled);
        $this->flash->success($enabled ? 'Document enabled and queued for re-indexing.' : 'Document disabled.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
