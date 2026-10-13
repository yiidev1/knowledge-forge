<?php

declare(strict_types=1);

namespace App\Agent\Web\Middleware;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admits anyone signed in through the Order58 login, whatever their role.
 *
 * ## Exactly one route needs this, and it is sign-out
 *
 * Agents and operation users share a login page but not an area: {@see RequireAgentMiddleware} admits
 * only agents, and {@see RequireOrderTestingAccessMiddleware} only operation users and administrators.
 * Signing out belongs to neither — it is the one thing both realms must be able to do, and refusing an
 * operation user at the agent gate would leave them with a session they cannot end.
 *
 * It deliberately checks nothing beyond "is there a session". Ending one is not a privileged act, and a
 * role check here could strand a user whose role was revoked mid-session — the exact case where signing
 * out matters most. The CSRF token on the form is what stops it being triggered from elsewhere.
 */
final readonly class RequireOrder58SessionMiddleware implements MiddlewareInterface
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

        $this->currentAgent->set($identity);

        return $handler->handle($request);
    }
}
