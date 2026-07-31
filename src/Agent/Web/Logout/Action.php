<?php

declare(strict_types=1);

namespace App\Agent\Web\Logout;

use App\Agent\Application\AgentLogoutService;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;

/**
 * Signs the agent out (POST /agent/logout).
 */
final readonly class Action
{
    public function __construct(
        private AgentLogoutService $logoutService,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->logoutService->logout();
        $this->flash->success('You have been signed out.');

        return $this->redirect->afterPost('agent.login.show');
    }
}
