<?php

declare(strict_types=1);

namespace App\Auth\Application;

use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Auth\Domain\AuthenticationResult;
use App\Auth\Domain\PasswordHasherInterface;
use SensitiveParameter;

/**
 * Authenticates an administrator and establishes their session.
 *
 * Everything about a failure is uniform: unknown username, wrong password and deactivated account all
 * return {@see AuthenticationResult::invalidCredentials()}, and the password is always verified —
 * against a decoy hash when the user does not exist — so the response time does not reveal whether the
 * username was real.
 */
final readonly class LoginService
{
    /**
     * A valid bcrypt hash of a random string, verified against when the username is unknown so that the
     * "no such user" path costs the same as the "wrong password" path. Its plaintext is unknown, so it
     * can never match a submitted password.
     */
    private const DECOY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEeODaQZ6q4TZG3W1e0m4Q2Qx8m0i7uXk9m';

    public function __construct(
        private AdminUserRepositoryInterface $users,
        private PasswordHasherInterface $hasher,
        private AdminIdentityStoreInterface $identityStore,
        private LoginThrottleInterface $throttle,
    ) {}

    public function login(
        string $username,
        #[SensitiveParameter]
        string $password,
        string $throttleKey,
    ): AuthenticationResult {
        $retryAfter = $this->throttle->retryAfterSeconds($throttleKey);

        if ($retryAfter !== null) {
            return AuthenticationResult::locked($retryAfter);
        }

        $user = $this->users->findByUsername($username);

        // Always run a verification, even with no user, so the timing of a missing account matches that
        // of a present one. The decoy can never match, so the branch outcome is unchanged.
        $hashToCheck = $user?->passwordHash() ?? self::DECOY_HASH;
        $passwordValid = $this->hasher->verify($password, $hashToCheck);

        if ($user === null || !$passwordValid || !$user->isActive()) {
            $this->throttle->registerFailure($throttleKey);

            return AuthenticationResult::invalidCredentials();
        }

        // Transparent upgrade: if the stored hash is weaker than the current default, replace it now
        // that we hold the plaintext. No forced reset, no separate migration.
        if ($this->hasher->needsRehash($user->passwordHash())) {
            $newHash = $this->hasher->hash($password);
            $this->users->updatePasswordHash($user->id(), $newHash);
            $user->replacePasswordHash($newHash);
        }

        $this->throttle->clear($throttleKey);
        $this->users->recordLogin($user->id());
        $this->identityStore->store($user->id());

        return AuthenticationResult::success($user);
    }
}
