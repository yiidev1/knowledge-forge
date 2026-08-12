<?php

declare(strict_types=1);

namespace App\Chat\Web\Sources;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Rules\Contract\StoreRuleReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Admin: the rules that apply to a store chat (GET /knowledge-bases/{slug}/chat/rules).
 *
 * The Order58 catalog list is store-scoped through the base's own Order58 `source_id`; a base with no Order58
 * link has no catalog rules to show at all, rather than falling back to "everything".
 */
final readonly class StoreRulesAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private KnowledgeBaseFinder $finder,
        private RuleRepositoryInterface $answeringRules,
        private KnowledgeBaseSourceRepositoryInterface $sources,
        private StoreRuleReaderInterface $storeRules,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $storeSourceId = $this->sources->findOrder58StoreId($knowledgeBase->id());

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(SourceViews::storeRules(), [
                'title' => $knowledgeBase->name() . ' — Rules',
                'contextName' => $knowledgeBase->name(),
                'answeringRules' => $this->answeringRules->findAllForKnowledgeBase($knowledgeBase->id()),
                'catalogRules' => $storeSourceId === null ? [] : $this->storeRules->findForStore($storeSourceId),
                'ruleChatUrl' => $this->ruleChatUrl(),
                'backUrl' => $this->urlGenerator->generate('chat.index', ['slug' => $slug]),
                'backLabel' => 'Back to chat',
            ]);
    }

    private function ruleChatUrl(): ?string
    {
        try {
            return $this->urlGenerator->generate('admin.rule-chat.index');
        } catch (Throwable) {
            return null;
        }
    }
}
