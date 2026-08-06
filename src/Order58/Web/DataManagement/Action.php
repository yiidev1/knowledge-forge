<?php

declare(strict_types=1);

namespace App\Order58\Web\DataManagement;

use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Order58\Domain\Order58KnowledgeRepositoryInterface;
use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncRunRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The Order58 Data Management page (GET /admin/order58).
 *
 * Renders entirely from local database state — mirror totals, the last cached health result, the latest
 * run per operation, and recent history. It never calls the Integration API on render; the three Sync
 * buttons and Check Connection only enqueue work for the worker.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private Order58StoreRepositoryInterface $stores,
        private Order58AgentRepositoryInterface $agents,
        private Order58KnowledgeRepositoryInterface $knowledge,
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private AgentStoreDirectoryInterface $agentDirectory,
        private SyncRunRepositoryInterface $runs,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                // "Active" here is strictly the Order58 source-active flag (order58_stores.active) — never
                // agent-enabled, vector-store or document readiness, which are shown as separate metrics.
                'stores' => ['all' => $this->stores->countAll(), 'active' => $this->stores->countActive()],
                'knowledgeBases' => $this->knowledgeBases->countByStatus(EnsureStoreKnowledgeBaseService::SOURCE),
                'availableToAgents' => $this->agentDirectory->countAvailable(),
                'agents' => ['all' => $this->agents->countAll(), 'active' => $this->agents->countActive()],
                'knowledge' => ['all' => $this->knowledge->countAll(), 'active' => $this->knowledge->countActive()],
                'latest' => $this->runs->latestByType(),
                'health' => $this->runs->latestHealth(),
                'recent' => $this->runs->recent(15),
                'active' => [
                    'stores' => $this->runs->hasActive(Order58SyncType::Stores, null),
                    'knowledge' => $this->runs->hasActive(Order58SyncType::Knowledge, null),
                    'agents' => $this->runs->hasActive(Order58SyncType::Agents, null),
                    'rules' => $this->runs->hasActive(Order58SyncType::Rules, null),
                ],
            ]);
    }
}
