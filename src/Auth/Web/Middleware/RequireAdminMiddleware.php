<?php

declare(strict_types=1);

namespace App\Auth\Web\Middleware;

use App\Auth\Application\AdminIdentityStoreInterface;
use App\Auth\Application\CurrentAdmin;
use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gate in front of every administrator route.
 *
 * An unauthenticated request is redirected to the login page. An authenticated one has its account
 * re-loaded from the database on every request — never trusted from the session alone — so that
 * deactivating or deleting an administrator takes effect on their very next click rather than whenever
 * their session happens to expire. The resolved account is published through {@see CurrentAdmin} for
 * downstream actions and the layout.
 */
final readonly class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminIdentityStoreInterface $identityStore,
        private AdminUserRepositoryInterface $users,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $adminId = $this->identityStore->currentId();

        if ($adminId === null) {
            return $this->redirect->toRoute('auth.login.show');
        }

        $admin = $this->users->findById($adminId);

        // The session names an account that no longer exists or has been deactivated. Clear the stale
        // identity so the browser is not stuck in a redirect loop, then send them to log in again.
        if ($admin === null || !$admin->isActive()) {
            $this->identityStore->clear();

            return $this->redirect->toRoute('auth.login.show');
        }

        $this->currentAdmin->setAdmin($admin);

        return $handler->handle($request);
    }
}
