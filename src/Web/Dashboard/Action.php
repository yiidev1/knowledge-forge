<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Auth\Application\CurrentAdmin;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Rules\Contract\RuleReadinessReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The administrator landing page (GET /), behind the auth middleware.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentAdmin $currentAdmin,
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
        private RuleReadinessReaderInterface $rules,
    ) {}

    public function __invoke(): ResponseInterface
    {
        // The rule tile counts through the readiness reader rather than a fresh COUNT(*), so the number here
        // is by construction the same one the Rule list lands on — the two cannot drift apart.
        $ruleSummary = $this->rules->summary('');

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'username' => $this->currentAdmin->get()->username(),
                'knowledgeBaseCount' => $this->knowledgeBases->countActive(excludeSystem: true),
                'ruleCount' => $ruleSummary->total(),
                'ruleReadyCount' => $ruleSummary->ready,
            ]);
    }
}
