<?php

declare(strict_types=1);

namespace App\Auth\Web\Middleware;

use App\Auth\Application\AdminIdentityStoreInterface;
use App\Auth\Application\CurrentAdmin;
use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Shared\Web\Actor\CurrentActor;
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
 * downstream actions and the layout, and through {@see CurrentActor} for the modules that may not name
 * this one.
 *
 * ## Why the same person is published twice
 *
 * {@see CurrentAdmin} carries the whole {@see \App\Auth\Domain\AdminUser}, which is what this
 * module's own pages want. {@see CurrentActor} carries an id and a realm and nothing else, because
 * Audio-to-Text and Order Testing are forbidden from naming `App\Auth` and have to learn who is acting
 * through the shared namespace instead. Both are filled from the one account this middleware has just
 * re-loaded, so they cannot disagree about who is acting.
 *
 * Leaving the second one unset is not a quiet omission — it is what broke audio upload and replacement
 * on the Audio-to-Text store page. Those actions build their actor from `CurrentActor`, an unresolved
 * holder reports no id, and the write refused with "an administrator actor needs a positive
 * admin_users id". {@see \App\Agent\Web\Middleware\RequireOrderTestingAccessMiddleware} fills both
 * for the routes it guards, which is why the same actions worked when reached through Order Testing and
 * not here.
 */
final readonly class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminIdentityStoreInterface $identityStore,
        private AdminUserRepositoryInterface $users,
        private CurrentAdmin $currentAdmin,
        private CurrentActor $actor,
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
        // The same account, in the neutral shape. `admin_users.id`, never Order58's `admin_id`: the two
        // are different people who happen to share a number, and `setAdmin` clears the operation id so
        // one holder can never claim both realms.
        $this->actor->setAdmin($admin->id(), $admin->username());

        return $handler->handle($request);
    }
}
