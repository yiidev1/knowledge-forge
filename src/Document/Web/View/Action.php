<?php

declare(strict_types=1);

namespace App\Document\Web\View;

use App\Document\Application\ServeCanonicalDocumentService;
use App\Document\Domain\DocumentKind;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET …/documents/{id}/view — canonical local source (text page or inline binary).
 */
final readonly class Action
{
    public function __construct(
        private ServeCanonicalDocumentService $serve,
        private KnowledgeBaseFinder $finder,
        private WebViewRenderer $viewRenderer,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $document = $this->serve->find($documentId, $knowledgeBase->id());

        if ($document->kind === DocumentKind::Pdf || $document->kind === DocumentKind::Image) {
            return $this->serve->streamInline($document);
        }

        $body = $this->serve->textBody($document);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'document' => $document,
                'body' => $body,
            ]);
    }
}
