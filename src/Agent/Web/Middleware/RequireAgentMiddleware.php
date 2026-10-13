<?php

declare(strict_types=1);

namespace App\Agent\Web\Middleware;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Agent\Domain\Order58Role;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gates every agent route: no agent identity in the session → redirect to the agent login. On success the
 * identity (validated at login) is published via {@see CurrentAgent}. Unlike the admin middleware there is
 * no per-request database reload — the session identity is trusted, so no network call happens on a page
 * view, which is the point of storing the safe identity rather than re-authenticating.
 */
final readonly class RequireAgentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AgentIdentityStoreInterface $identityStore,
        private CurrentAgent $currentAgent,
        private Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $this->identityStore->current();
        if ($identity === null) {
            return $this->redirect->toRoute('agent.login.show');
        }

        // The same login page now issues sessions to two roles. An operation user holds a perfectly
        // valid Order58 session and must still be refused here: their area is Order Testing, and the
        // agent app — store chat, rule chat, the rule list — is not theirs. Sending them to their own
        // home rather than to a login form matters, because they have already passed one.
        $role = Order58Role::fromUserType($identity->userType);

        if ($role === null || !$role->canAccessAgentArea()) {
            return $this->redirect->toRoute($role?->homeRoute() ?? 'agent.login.show');
        }

        $this->currentAgent->set($identity);

        return $handler->handle($request);
    }
}
