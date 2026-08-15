<?php

declare(strict_types=1);

namespace App\Order58\Contract;

use App\Order58\Contract\Dto\Order58ValidationResult;
use SensitiveParameter;

/**
 * A second opinion on a username/password, from the Order58 validate API.
 *
 * This is a credential oracle and nothing more. It answers "are these credentials valid?" and cannot say who
 * the user is: the response carries no `admin_id`, no `username`, no `user_type` and no `status`. Its
 * `account_id` is the *employer* account (one value covers hundreds of users) and is never read. Authorising
 * the resulting login is therefore a separate step, against trusted local data — see
 * {@see \App\Agent\Domain\TrustedAgentDirectoryInterface}.
 *
 * Never throws. Every failure — network, upstream, our own token, missing configuration — comes back as an
 * outcome, so the caller can distinguish "the password is wrong" from "we could not ask", which is the
 * distinction that decides whether the login throttle is charged.
 */
interface Order58CredentialValidatorInterface
{
    /**
     * @param string $login    Exactly what the agent typed.
     * @param string $password Exactly what the agent typed. Never logged, never stored, never hashed here.
     */
    public function validate(
        string $login,
        #[SensitiveParameter]
        string $password,
    ): Order58ValidationResult;
}
