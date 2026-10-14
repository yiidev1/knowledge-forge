<?php

declare(strict_types=1);

namespace App\Agent\Web\Middleware;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentEligibilityReaderInterface;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Web\Actor\CurrentActor;
use App\Shared\Web\Actor\PracticeStoreScope;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

use function is_string;

/**
 * The gate in front of the agent practice surface, and nothing else.
 *
 * ## Why it is a nested group rather than a change to the agent gate
 *
 * {@see RequireAgentMiddleware} is **not modified**. It already proved the session before this runs, and
 * it goes on governing the agent home, store chat, rule chat and the rule list exactly as it did. Three
 * things only this surface needs — an actor identity, a per-request role re-check and an object-level
 * scope — therefore live here, on a group containing only the practice routes. A regression in the rest
 * of the agent area is not unlikely; it is unreachable.
 *
 * ## Three things it adds
 *
 * **1. An actor.** The agent area needs none, so {@see RequireAgentMiddleware} sets no
 * {@see CurrentActor}. This surface writes one thing — a Demo URL attempt — and that row records who
 * started it. `setAgent()` publishes Order58's own `admin_id`, the same identity the session carries, so
 * an attempt is traceable to a real account rather than to "an agent".
 *
 * **2. The role, re-read on this request.** The agent gate deliberately trusts the session and opens no
 * connection on a page view. That is right for reading chat; it is not right for the one surface that
 * writes. An agent disabled in Order58 five minutes ago must not be able to start an attempt, so their
 * role is answered from the mirror here, on every request, at the cost of one query.
 *
 * **3. Object-level scope.** Knowing a store id or a job id is not permission. The listing shows only
 * qualifying stores, but a URL can be typed, so {@see PracticeStoreScope} re-answers the same question
 * for whatever this request actually names — a store, or a recording, including the routes that serve
 * audio bytes. The listing and this check are built from one SQL predicate, so they cannot disagree.
 *
 * A refusal is a **404**, not a redirect: an agent asking about a store they may not see should not learn
 * from the answer that it exists.
 */
final readonly class RequirePracticeOrdersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AgentIdentityStoreInterface $agentIdentity,
        private AgentEligibilityReaderInterface $eligibility,
        private CurrentAgent $currentAgent,
        private CurrentActor $actor,
        private PracticeStoreScope $scope,
        private CurrentRoute $currentRoute,
        private Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $this->agentIdentity->current();

        if ($identity === null) {
            // Unreachable behind the agent gate, which redirects first. Kept because this class must be
            // correct on its own terms: a group wired without that gate would otherwise run unguarded.
            return $this->redirect->toRoute('agent.login.show');
        }

        $role = $this->eligibility->currentRoleFor($identity->adminId);

        if ($role === null) {
            // Disabled, deleted, or no longer an admitted type. Clear the session so the next request
            // starts clean rather than re-deciding the same revocation.
            $this->agentIdentity->clear();

            return $this->redirect->toRoute('agent.login.show');
        }

        if (!$role->canAccessAgentArea()) {
            // An operation user holding a valid Order58 session. Their practice surface is the Order
            // Testing one, which they already reach; send them to their own home rather than a login
            // form they have satisfied.
            return $this->redirect->toRoute($role->homeRoute());
        }

        $this->assertWithinScope();

        $this->currentAgent->set($identity);
        $this->actor->setAgent($identity->adminId, $identity->displayName);

        return $handler->handle($request);
    }

    /**
     * Whatever object this route names, does the agent's predicate admit it?
     *
     * Both argument names are checked, not one: a store-addressed route carries `sourceId`, a
     * recording-addressed one carries `publicId`, and the audio file routes carry only the latter. A
     * route carrying neither is page-level and has no object to scope.
     */
    private function assertWithinScope(): void
    {
        $sourceId = $this->currentRoute->getArgument('sourceId');

        if (is_string($sourceId) && $sourceId !== '' && !$this->scope->admitsStore((int) $sourceId)) {
            throw new NotFoundException('practice_store_not_found', 'No such store.');
        }

        $publicId = $this->currentRoute->getArgument('publicId');

        if (is_string($publicId) && $publicId !== '' && !$this->scope->admitsJob($publicId)) {
            throw new NotFoundException('practice_job_not_found', 'No such recording.');
        }
    }
}
