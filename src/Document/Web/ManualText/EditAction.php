<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\Document\Application\ServeCanonicalDocumentService;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\Exception\DocumentNotFound;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET …/documents/{id}/edit — type-aware edit form (manual, uploaded text, Order58, PDF/image).
 */
final readonly class EditAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private ServeCanonicalDocumentService $serve,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $document = $this->serve->find($documentId, $knowledgeBase->id());

        if ($document->isManualText() || $document->isUploadedText() || $document->isOrder58()) {
            $content = $this->serve->textBody($document);

            return $this->viewRenderer
                ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
                ->render(__DIR__ . '/form', [
                    'knowledgeBase' => $knowledgeBase,
                    'mode' => 'edit',
                    'documentId' => $document->id,
                    'title' => $document->displayTitle(),
                    'content' => $content,
                    'sourceType' => $document->sourceType,
                    'isSourceOverridden' => $document->isSourceOverridden,
                    'readOnly' => false,
                ]);
        }

        if ($document->kind === DocumentKind::Pdf || $document->kind === DocumentKind::Image) {
            return $this->viewRenderer
                ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
                ->render(__DIR__ . '/binary-form', [
                    'knowledgeBase' => $knowledgeBase,
                    'document' => $document,
                ]);
        }

        throw DocumentNotFound::inKnowledgeBase($documentId, $knowledgeBase->id());
    }
}
