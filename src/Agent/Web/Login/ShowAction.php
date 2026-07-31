<?php

declare(strict_types=1);

namespace App\Agent\Web\Login;

use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Renders the agent login form (GET /agent/login). An already-signed-in agent is bounced to their home.
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AgentIdentityStoreInterface $identityStore,
        private Redirect $redirect,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if ($this->identityStore->current() !== null) {
            return $this->redirect->toRoute('agent.home');
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Auth/layout.php')
            ->render(__DIR__ . '/template', ['username' => '']);
    }
}
