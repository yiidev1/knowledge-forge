<?php

declare(strict_types=1);

namespace App\Agent\Web\Sources;

use App\Chat\Web\Sources\SourceViews;
use App\Chat\Application\RuleChatAvailability;
use App\Rules\Contract\RuleReadinessReaderInterface;
use App\Rules\Domain\RuleReadinessFilter;
use App\Rules\Domain\RuleReadinessQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function max;

/**
 * Agent: the indexed global rules the agent's Rule Chat can search (GET /agent/rule-chat/rules).
 *
 * The rules base is global and identical for every agent — the agent Rule Chat already answers from it — so
 * listing its indexed contents discloses nothing an agent could not obtain by asking. The set is the same
 * `Ready` + `hiddenBaseOnly` derivation the admin page uses; no admin-only column is rendered.
 */
final readonly class RuleChatRulesAction
{
    private const PER_PAGE = 25;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReadinessReaderInterface $reader,
        private RuleChatAvailability $availability,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        $result = $this->reader->list(new RuleReadinessQuery(
            search: '',
            filter: RuleReadinessFilter::Ready,
            page: $page,
            perPage: self::PER_PAGE,
            hiddenBaseOnly: true,
        ));

        return $this->viewRenderer
            ->withLayout('@src/Agent/Web/Layout/layout.php')
            ->render(SourceViews::ruleChatRules(), [
                'title' => 'Rule Chat — Rules',
                'result' => $result,
                'page' => $result->currentPage(),
                'chatReady' => $this->availability->isAvailable(),
                'pageRoute' => 'agent.rule-chat.sources.rules',
                'backUrl' => $this->urlGenerator->generate('agent.rule-chat.index'),
                'backLabel' => 'Back to chat',
            ]);
    }
}
