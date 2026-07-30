<?php

declare(strict_types=1);

namespace App\Auth\Web\Logout;

use App\Auth\Application\LogoutService;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;

/**
 * Ends the session (POST /logout).
 *
 * POST-only and CSRF-protected, so a third-party page cannot force a logout with a bare link or image.
 */
final readonly class Action
{
    public function __construct(
        private LogoutService $logoutService,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->logoutService->logout();
        $this->flash->success('You have been signed out.');

        return $this->redirect->afterPost('auth.login.show');
    }
}
