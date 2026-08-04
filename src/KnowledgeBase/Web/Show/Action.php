<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Show;

use App\Chat\Application\ChatAvailabilityPolicy;
use App\Document\Application\Validation\SupportedFileTypes;
use App\Document\Domain\DocumentListItem;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Order58\Application\Order58DisplayParams;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_values;

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
        private Order58DisplayParams $order58Display,
        private ChatAvailabilityPolicy $availability,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $documents = $this->documents->findListForKnowledgeBase($knowledgeBase->id());

        if (!$this->order58Display->showStoreProfileDocuments) {
            $documents = array_values(array_filter(
                $documents,
                static fn(DocumentListItem $document): bool => $document->sourceType !== DocumentSourceType::Order58StoreProfile,
            ));
        }

        $chatReason = $this->availability->getUnavailableReason($knowledgeBase);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'knowledgeBase' => $knowledgeBase,
                'rules' => $this->rules->findAllForKnowledgeBase($knowledgeBase->id()),
                'documents' => $documents,
                'uploadAccept' => SupportedFileTypes::acceptAttribute(),
                'order58StoreId' => $this->source->findOrder58StoreId($knowledgeBase->id()),
                'chatAvailable' => $chatReason->isAvailable(),
                'chatUnavailableMessage' => $chatReason->message(),
            ]);
    }
}
