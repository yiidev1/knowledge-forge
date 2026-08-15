<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Contract\Dto\Order58ValidationOutcome;
use App\Order58\Contract\Dto\Order58ValidationResult;
use App\Order58\Contract\Order58CredentialValidatorInterface;
use SensitiveParameter;

/**
 * A programmable fallback validator. Records the logins it was asked about so a test can prove the fallback
 * was (or was not) consulted; passwords are deliberately never recorded, mirroring {@see FakeOrder58Client}.
 */
final class FakeOrder58CredentialValidator implements Order58CredentialValidatorInterface
{
    public Order58ValidationOutcome $outcome = Order58ValidationOutcome::CredentialsRejected;
    public ?string $safeMessage = null;

    /** Logins validate() was called with, in order. */
    public array $logins = [];

    public function validate(
        string $login,
        #[SensitiveParameter]
        string $password,
    ): Order58ValidationResult {
        $this->logins[] = $login;

        return Order58ValidationResult::of($this->outcome, $this->safeMessage);
    }

    public function calls(): int
    {
        return count($this->logins);
    }
}
