<?php

declare(strict_types=1);

namespace App\Agent\Web\Sources;

use App\Agent\Web\Chat\AgentStoreResolver;
use App\Chat\Web\Sources\SourceViews;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\Rules\Contract\StoreRuleReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Agent: the rules that apply to an agent's store chat (GET /agent/stores/{slug}/chat/rules).
 *
 * Store scoping is doubly enforced: {@see AgentStoreResolver} decides the agent may see this store at all, and
 * the catalog query then keys on that store's own Order58 `source_id`, so another store's rules are
 * unreachable even by editing the URL.
 */
final readonly class StoreRulesAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentStoreResolver $resolver,
        private RuleRepositoryInterface $answeringRules,
        private KnowledgeBaseSourceRepositoryInterface $sources,
        private StoreRuleReaderInterface $storeRules,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->resolver->resolve($slug);
        $storeSourceId = $this->sources->findOrder58StoreId($knowledgeBase->id());

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(SourceViews::storeRules(), [
                'title' => $knowledgeBase->name() . ' — Rules',
                'contextName' => $knowledgeBase->name(),
                'answeringRules' => $this->answeringRules->findAllForKnowledgeBase($knowledgeBase->id()),
                'catalogRules' => $storeSourceId === null ? [] : $this->storeRules->findForStore($storeSourceId),
                'ruleChatUrl' => $this->ruleChatUrl(),
                'backUrl' => $this->urlGenerator->generate('agent.chat.index', ['slug' => $slug]),
                'backLabel' => 'Back to chat',
            ]);
    }

    private function ruleChatUrl(): ?string
    {
        try {
            return $this->urlGenerator->generate('agent.rule-chat.index');
        } catch (Throwable) {
            return null;
        }
    }
}
