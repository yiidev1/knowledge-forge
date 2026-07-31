<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\AgentAuthenticationResult;
use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentIdentityStoreInterface;
use App\Auth\Application\LoginThrottleInterface;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use SensitiveParameter;

/**
 * Authenticates an Order58 agent, entirely server-side.
 *
 * The password is posted to Order58 `POST /authenticate` and is never stored, hashed locally, or logged.
 * Only agents are admitted: the account must authenticate **and** have `user_type === 'agent'` and
 * `status === 'active'` — merchant/admin/operation/trainee accounts are rejected with the same generic
 * result as a wrong password. On success only the safe identity is placed in the session.
 */
final readonly class AgentLoginService
{
    private const REQUIRED_USER_TYPE = 'agent';
    private const REQUIRED_STATUS = 'active';

    public function __construct(
        private Order58ClientInterface $client,
        private AgentIdentityStoreInterface $identityStore,
        private LoginThrottleInterface $throttle,
    ) {}

    public function login(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $throttleKey,
    ): AgentAuthenticationResult {
        $retryAfter = $this->throttle->retryAfterSeconds($throttleKey);
        if ($retryAfter !== null) {
            return AgentAuthenticationResult::locked($retryAfter);
        }

        try {
            $result = $this->client->authenticate($username, $password);
        } catch (Order58Exception) {
            // A rejected Bearer token or a transport failure is an operational problem, not the agent's
            // fault — do not register a throttle failure for it.
            return AgentAuthenticationResult::unavailable();
        }

        $agent = $result->agent;
        if (
            !$result->authenticated
            || $agent === null
            || $agent->userType !== self::REQUIRED_USER_TYPE
            || $agent->status !== self::REQUIRED_STATUS
        ) {
            $this->throttle->registerFailure($throttleKey);

            return AgentAuthenticationResult::invalidCredentials();
        }

        $this->throttle->clear($throttleKey);
        $identity = AgentIdentity::fromAgent($agent);
        $this->identityStore->store($identity);

        return AgentAuthenticationResult::success($identity);
    }
}
