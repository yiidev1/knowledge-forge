<?php

declare(strict_types=1);

namespace App\Agent\Web\Middleware;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentEligibilityReaderInterface;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Auth\Application\AdminIdentityStoreInterface;
use App\Auth\Application\CurrentAdmin;
use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Shared\Web\Actor\CurrentActor;
use App\Shared\Web\Actor\OrderTestingJobScope;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

use function is_string;

/**
 * Gate in front of the Order Testing workflow, which two realms may use.
 *
 * ## The two doors, and why they are not the same door
 *
 * An **administrator** is admitted exactly as {@see \App\Auth\Web\Middleware\RequireAdminMiddleware}
 * admits them — same session key, same per-request reload from `admin_users`, same `isActive()` check,
 * same stale-session clear, same redirect. That is reproduced here rather than delegated so that adding
 * a second realm cannot change the first one's behaviour by accident. An administrator's experience of
 * Order Testing is byte-for-byte what it was.
 *
 * An **operation user** is admitted from the Order58 session, and only after their role and status are
 * re-read from the local mirror on this very request. Nothing is trusted from the session but the
 * `admin_id`; whether that person may still act is answered from `order58_agents` every time, so a
 * disabled account or one whose `user_type` changed loses access on its next click rather than whenever
 * the session happens to expire.
 *
 * **Agents are refused.** They authenticate through the same login page, so a signed-in agent reaching
 * an Order Testing URL is an ordinary wrong-realm request, not an error — they are sent to their own
 * area, not to a login form they have already passed.
 *
 * ## Object-level scope, because a job id is not permission
 *
 * Several routes in this group are audio endpoints the store page calls, addressed only by `{publicId}`
 * and shared with the Audio-to-Text module. `audio_conversations.store_source_id` and `.order_id` are
 * both nullable, so jobs can exist that no Order Testing page lists. For an operation request carrying a
 * `publicId`, {@see OrderTestingJobScope} must therefore confirm the job resolves to a real mirrored
 * store **and** a real mirrored order for that store before the request proceeds. Administrators skip
 * the check and pay nothing for it.
 *
 * A refusal here is a 404, matching how this application already answers a request for an id the caller
 * may not see — telling them the job exists but is out of their scope would leak its existence.
 *
 * ## What this gate does **not** grant
 *
 * Only the routes placed in this group. The Audio-to-Text module's own pages, the global transcription
 * setting, the dashboard, stores, chat, rules, reports and the agent area all stay where they were,
 * behind their own gates.
 */
final readonly class RequireOrderTestingAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AdminIdentityStoreInterface $adminIdentity,
        private AdminUserRepositoryInterface $admins,
        private CurrentAdmin $currentAdmin,
        private AgentIdentityStoreInterface $agentIdentity,
        private CurrentAgent $currentAgent,
        private AgentEligibilityReaderInterface $eligibility,
        private CurrentActor $actor,
        private OrderTestingJobScope $jobScope,
        private CurrentRoute $currentRoute,
        private Redirect $redirect,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $adminResponse = $this->admitAdministrator($request, $handler);

        if ($adminResponse !== null) {
            return $adminResponse;
        }

        return $this->admitOperationUser($request, $handler);
    }

    /**
     * The administrator door, reproducing the existing gate exactly.
     *
     * Returns null when there is no administrator session at all, so the caller can try the other realm.
     * A *broken* administrator session — deactivated or deleted — is answered here rather than falling
     * through, because somebody holding a stale admin cookie is an administrator with a problem, not a
     * candidate operation user.
     */
    private function admitAdministrator(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ?ResponseInterface {
        $adminId = $this->adminIdentity->currentId();

        if ($adminId === null) {
            return null;
        }

        $admin = $this->admins->findById($adminId);

        if ($admin === null || !$admin->isActive()) {
            $this->adminIdentity->clear();

            return $this->redirect->toRoute('auth.login.show');
        }

        $this->currentAdmin->setAdmin($admin);
        $this->actor->setAdmin($admin->id(), $admin->username());

        return $handler->handle($request);
    }

    private function admitOperationUser(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $identity = $this->agentIdentity->current();

        if ($identity === null) {
            // Nobody is signed in at all. The administrator login is the right destination: this
            // workflow's long-standing audience is administrators, and an operation user who followed a
            // bookmark can still reach their own login from there.
            return $this->redirect->toRoute('auth.login.show');
        }

        // Authoritative on this request, not whatever the session recorded at sign-in.
        $role = $this->eligibility->currentRoleFor($identity->adminId);

        if ($role === null) {
            // Disabled, deleted, or no longer an admitted type. Clear the session so the next request
            // starts clean rather than re-deciding the same revocation.
            $this->agentIdentity->clear();

            return $this->redirect->toRoute('agent.login.show');
        }

        if (!$role->canAccessOrderTesting()) {
            // A signed-in agent. They are authenticated and their session is fine — send them to their
            // own area rather than to a login form they have already satisfied.
            return $this->redirect->toRoute($role->homeRoute());
        }

        if (!$this->withinOrderTestingScope()) {
            return $this->redirect->toRoute('order-testing');
        }

        $this->currentAgent->set($identity);
        $this->actor->setOperation($identity->adminId, $identity->displayName);

        return $handler->handle($request);
    }

    /**
     * For a job-addressed route, is this job one the Order Testing workflow actually reaches?
     *
     * Routes without a `publicId` are page-level and carry no object to scope, so they pass. Routes with
     * one are checked against the store/order relationship before the action ever runs.
     */
    private function withinOrderTestingScope(): bool
    {
        $publicId = $this->currentRoute->getArgument('publicId');

        if (!is_string($publicId) || $publicId === '') {
            return true;
        }

        return $this->jobScope->admitsJob($publicId);
    }
}
