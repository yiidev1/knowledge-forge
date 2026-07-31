<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\Document\Domain\Exception\DocumentNotFound;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The "Edit manual text" form (GET /knowledge-bases/{slug}/documents/{documentId}/edit).
 *
 * Only manual-text documents are editable: an uploaded file resolves through the same not-found path as a
 * missing id, so the form never opens on content the admin cannot safely rewrite. The stored original text
 * is shown verbatim for editing — the normalized, indexed copy is derived from it on save.
 */
final readonly class EditAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private TextDocumentRepositoryInterface $texts,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $document = $this->texts->findEditable($documentId, $knowledgeBase->id());
        if ($document === null || !$document->isManual()) {
            throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBase->id());
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/form', [
                'knowledgeBase' => $knowledgeBase,
                'mode' => 'edit',
                'documentId' => $document->id,
                'title' => $document->title,
                'content' => $document->sourceText,
            ]);
    }
}
