<?php

declare(strict_types=1);

namespace App\Agent\Application;

use App\Agent\Domain\TrustedAgentDirectoryInterface;
use App\Order58\Contract\Dto\Order58ValidationOutcome;
use App\Order58\Contract\Order58CredentialValidatorInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The second opinion on an agent's credentials, reached only after the Order58 Integration API has explicitly
 * rejected them.
 *
 * Two steps that must stay separate, because collapsing them is how a privilege escalation gets built:
 *
 * 1. **Validation** — the fallback API says whether the password is correct. That is all it can say: its
 *    response carries no `admin_id`, no `username`, no `user_type` and no `status`, and its `account_id` is
 *    the employer account, shared by hundreds of users of every kind, so it is never read.
 * 2. **Authorization** — the entered username is resolved against the trusted local mirror, which must yield
 *    exactly one active agent, synced recently enough to be believed. A validated admin or merchant fails
 *    here, as it must.
 *
 * Everything that is not a verdict about the password — a network failure, an upstream error, our own token
 * being refused, missing configuration — returns `unavailable` so the caller leaves the login throttle
 * alone. Only a real rejection is charged.
 */
final readonly class FallbackAgentAuthenticator
{
    public function __construct(
        private Order58CredentialValidatorInterface $validator,
        private TrustedAgentDirectoryInterface $agents,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $maxMirrorAgeHours,
    ) {}

    public function authenticate(
        string $username,
        #[SensitiveParameter]
        string $password,
    ): FallbackAgentAuthentication {
        $validation = $this->validator->validate($username, $password);

        if (!$validation->outcome->isCredentialVerdict()) {
            // We could not ask, or the answer was about us rather than about the password.
            $this->note($validation->outcome->reason());

            return FallbackAgentAuthentication::unavailable($validation->safeMessage);
        }

        if ($validation->outcome === Order58ValidationOutcome::CredentialsRejected) {
            $this->note($validation->outcome->reason());

            return FallbackAgentAuthentication::rejected();
        }

        // Credentials are valid. Who they belong to is a separate question, answered locally.
        $notSyncedBefore = $this->clock->now()->modify('-' . $this->maxMirrorAgeHours . ' hours');
        $lookup = $this->agents->findActiveAgentByUsername($username, $notSyncedBefore);

        if ($lookup->agent === null) {
            // Valid password, but no single active agent we can safely say it belongs to.
            $this->note($lookup->reason);

            return FallbackAgentAuthentication::rejected();
        }

        $this->note('fallback_login_admitted');

        return FallbackAgentAuthentication::admitted($lookup->agent);
    }

    /**
     * A fixed reason code and nothing else. No username (it is not on the SafeLogContext allowlist and would
     * be dropped anyway), no password, no token, no upstream payload. The correlation id already ties the
     * line to the request.
     */
    private function note(string $reason): void
    {
        $this->logger->info('agent fallback authentication', ['reason' => $reason]);
    }
}
