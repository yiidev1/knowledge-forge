<?php

declare(strict_types=1);

namespace App\Web\SignIn;

use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Agent\Domain\Order58Role;
use App\Auth\Application\AdminIdentityStoreInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * `GET /login` — the one sign-in page.
 *
 * Somebody already signed in is sent to their own area rather than shown a form they have passed: an
 * administrator to the dashboard, an agent or operation user to the home of their role. Each area's own
 * gate still checks them on arrival, so this is a convenience and never the authorization.
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AdminIdentityStoreInterface $adminSession,
        private AgentIdentityStoreInterface $order58Session,
        private Redirect $redirect,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if ($this->adminSession->currentId() !== null) {
            return $this->redirect->toRoute('dashboard');
        }

        $identity = $this->order58Session->current();
        $role = $identity === null ? null : Order58Role::fromUserType($identity->userType);

        if ($role !== null) {
            return $this->redirect->toRoute($role->homeRoute());
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Auth/layout.php')
            ->render(__DIR__ . '/template', ['username' => '']);
    }
}
