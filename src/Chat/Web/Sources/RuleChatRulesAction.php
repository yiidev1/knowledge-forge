<?php

declare(strict_types=1);

namespace App\Chat\Web\Sources;

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
 * Admin: the indexed global rules Rule Chat can search (GET /admin/rule-chat/rules).
 *
 * Scope is not hand-rolled — {@see RuleReadinessFilter::Ready} plus `hiddenBaseOnly` is exactly the set that
 * makes {@see RuleChatAvailability} report the surface as answerable, so this page and the chat can never
 * disagree about what is usable.
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
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(SourceViews::ruleChatRules(), [
                'title' => 'Rule Chat — Rules',
                'result' => $result,
                'page' => $result->currentPage(),
                'chatReady' => $this->availability->isAvailable(),
                'pageRoute' => 'admin.rule-chat.sources.rules',
                'backUrl' => $this->urlGenerator->generate('admin.rule-chat.index'),
                'backLabel' => 'Back to chat',
            ]);
    }
}
