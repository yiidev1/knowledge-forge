<?php

declare(strict_types=1);

namespace App\Web\SignIn;

use App\Agent\Application\AgentLoginService;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Agent\Domain\Order58Role;
use App\Agent\Domain\TrustedAgentDirectoryInterface;
use App\Agent\Domain\TrustedAgentLookupResult;
use App\Agent\Web\Login\AuthenticateAction as Order58LoginAction;
use App\Auth\Application\AdminIdentityStoreInterface;
use App\Auth\Application\LoginService;
use App\Auth\Application\ThrottleKey;
use App\Auth\Domain\AdminUserRepositoryInterface;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * One sign-in form for administrators, agents and operation users, over the two logins that already exist.
 *
 * ## Nothing here verifies a password
 *
 * Administrators are checked by {@see LoginService} against `admin_users`, exactly as `/login` always did.
 * Agents and operation users are checked by {@see AgentLoginService} against Order58, exactly as
 * `/agent/login` always did — role allow-list, active status, fallback and all. Each keeps its own
 * throttle namespace, so lock-outs behave as they did on the page each person used before. This class only
 * decides which of the two a username belongs to, and tidies the session afterwards.
 *
 * ## Which login a username goes to
 *
 * A username with a local administrator account goes to the administrator login. Every other username goes
 * to Order58, which is what the agent page did with anything typed into it.
 *
 * The one case that needs a rule is a username held by BOTH an administrator and an active Order58 agent or
 * operation user (none exists today; nothing stops one tomorrow). **The password decides**: the
 * administrator account is tried first, and only if its password does not match is the Order58 account
 * tried. Nobody can reach the administrator area without the administrator's own password, so the order
 * grants nothing; and an administrator's mistyped password is sent to Order58 only when Order58 really does
 * have an eligible account of that name — never for an administrator-only username, because a near-miss of
 * a password is not something to post to a third party.
 *
 * ## One realm at a time
 *
 * A browser that signed in as one person and now signs in as another must not keep the first identity. Both
 * logins already regenerate the session; on success the OTHER realm's identity is cleared as well, so a
 * session never carries an administrator and an Order58 user at once through this form.
 */
final readonly class SignInService
{
    public function __construct(
        private AdminUserRepositoryInterface $admins,
        private LoginService $adminLogin,
        private AgentLoginService $order58Login,
        /** Read only to learn whether Order58 has an eligible account of a name — see the class notes. */
        private TrustedAgentDirectoryInterface $order58Accounts,
        private AdminIdentityStoreInterface $adminSession,
        private AgentIdentityStoreInterface $order58Session,
    ) {}

    public function signIn(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $clientIp,
    ): SignInResult {
        $adminLocked = null;

        if ($this->admins->findByUsername($username) !== null) {
            $admin = $this->adminLogin->login($username, $password, ThrottleKey::for($username, $clientIp));

            if ($admin->isSuccess()) {
                $this->order58Session->clear();

                return SignInResult::signedIn('dashboard');
            }

            if (!$this->order58AccountExists($username)) {
                return $admin->isLocked()
                    ? SignInResult::locked((int) $admin->retryAfterSeconds())
                    : SignInResult::invalid();
            }

            // The same name is an Order58 account too, so its own password gets its own check below.
            $adminLocked = $admin->isLocked() ? (int) $admin->retryAfterSeconds() : null;
        }

        $result = $this->order58Login->login(
            $username,
            $password,
            ThrottleKey::for(Order58LoginAction::THROTTLE_NAMESPACE . ':' . $username, $clientIp),
        );

        if ($result->success && $result->agent !== null) {
            $this->adminSession->clear();

            // The role was allow-listed by the login itself, so it is never null here; the agent home is
            // the same fallback the agent page used.
            return SignInResult::signedIn(
                Order58Role::fromUserType($result->agent->userType)?->homeRoute() ?? 'agent.home',
            );
        }

        if ($result->locked) {
            return SignInResult::locked((int) $result->retryAfterSeconds);
        }

        // An administrator held off by their own lock-out hears about the lock, not "invalid".
        if ($adminLocked !== null) {
            return SignInResult::locked($adminLocked);
        }

        if ($result->unavailable) {
            return SignInResult::unavailable($result->message);
        }

        return SignInResult::invalid();
    }

    /**
     * Whether the Order58 mirror holds an active agent or operation user of this name.
     *
     * The fallback login's own lookup, asked without a freshness limit: here it only answers "does such an
     * account exist", and any answer but "none" — one row, several, or one not synced lately — means it
     * might, so its password deserves its own check.
     */
    private function order58AccountExists(string $username): bool
    {
        $lookup = $this->order58Accounts->findActiveAgentByUsername($username, new DateTimeImmutable('@0'));

        return $lookup->reason !== TrustedAgentLookupResult::notFound()->reason;
    }
}
