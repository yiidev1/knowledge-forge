<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Show;

use App\Document\Application\Validation\SupportedFileTypes;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Knowledge-base detail page (GET /knowledge-bases/{slug}): overview, provisioning status, documents
 * and rule management.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private RuleRepositoryInterface $rules,
        private TextDocumentRepositoryInterface $documents,
        private KnowledgeBaseSourceRepositoryInterface $source,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'rules' => $this->rules->findAllForKnowledgeBase($knowledgeBase->id()),
                'documents' => $this->documents->findListForKnowledgeBase($knowledgeBase->id()),
                'uploadAccept' => SupportedFileTypes::acceptAttribute(),
                'order58StoreId' => $this->source->findOrder58StoreId($knowledgeBase->id()),
            ]);
    }
}
