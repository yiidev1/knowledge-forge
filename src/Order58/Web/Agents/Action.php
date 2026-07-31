<?php

declare(strict_types=1);

namespace App\Order58\Web\Agents;

use App\Order58\Domain\Order58AgentRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The read-only mirrored-agents page (GET /admin/order58/agents). Shows the safe agent profile only —
 * never a password, token or credential (the Integration API returns none). `account_id` is shown as
 * employer/profile data and is explicitly not a store permission.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private Order58AgentRepositoryInterface $agents,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'agents' => $this->agents->findAllForDisplay(),
            ]);
    }
}
