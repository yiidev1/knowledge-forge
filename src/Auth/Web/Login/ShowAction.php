<?php

declare(strict_types=1);

namespace App\Auth\Web\Login;

use App\Auth\Application\AdminIdentityStoreInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Renders the login form (GET /login).
 *
 * Already-authenticated visitors are bounced to the dashboard rather than shown the form again — the
 * middleware re-validates the account there, so a stale session id cannot slip through this shortcut.
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private AdminIdentityStoreInterface $identityStore,
        private Redirect $redirect,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if ($this->identityStore->currentId() !== null) {
            return $this->redirect->toRoute('dashboard');
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Auth/layout.php')
            ->render(__DIR__ . '/template', ['username' => '']);
    }
}
