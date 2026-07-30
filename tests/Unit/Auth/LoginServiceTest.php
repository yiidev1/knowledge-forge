<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Application\LoginService;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Tests\Support\Fake\Auth\InMemoryAdminUserRepository;
use App\Tests\Support\Fake\Auth\InMemoryIdentityStore;
use App\Tests\Support\Fake\Auth\InMemoryLoginThrottle;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class LoginServiceTest extends Unit
{
    private const PASSWORD = 'correct horse battery staple';
    private const KEY = 'throttle-key';

    private InMemoryAdminUserRepository $users;
    private InMemoryIdentityStore $identity;
    private InMemoryLoginThrottle $throttle;
    private NativePasswordHasher $hasher;
    private LoginService $service;

    protected function _before(): void
    {
        $this->users = new InMemoryAdminUserRepository();
        $this->identity = new InMemoryIdentityStore();
        $this->throttle = new InMemoryLoginThrottle();
        $this->hasher = new NativePasswordHasher();
        $this->service = new LoginService($this->users, $this->hasher, $this->identity, $this->throttle);
    }

    public function testValidCredentialsAuthenticateAndEstablishSession(): void
    {
        $this->users->add('admin', $this->hasher->hash(self::PASSWORD));

        $result = $this->service->login('admin', self::PASSWORD, self::KEY);

        assertTrue($result->isSuccess());
        assertSame('admin', $result->admin()?->username());
        assertSame(1, $this->identity->storeCalls, 'session identity must be stored');
        assertSame(1, $this->throttle->clearCalls, 'throttle must be cleared on success');
        assertSame(1, $this->users->recordLoginCalls, 'last-login timestamp must be recorded');
    }

    public function testWrongPasswordIsRejectedAndCounted(): void
    {
        $this->users->add('admin', $this->hasher->hash(self::PASSWORD));

        $result = $this->service->login('admin', 'not the password', self::KEY);

        assertFalse($result->isSuccess());
        assertFalse($result->isLocked());
        assertSame(0, $this->identity->storeCalls);
        assertSame(1, $this->throttle->failureCalls, 'a wrong password must register a throttle failure');
    }

    /**
     * Unknown username and wrong password must be indistinguishable to the caller — no field on the
     * result reveals which it was.
     */
    public function testUnknownUsernameLooksExactlyLikeAWrongPassword(): void
    {
        $wrongPassword = $this->service->login('ghost', self::PASSWORD, self::KEY);

        assertFalse($wrongPassword->isSuccess());
        assertFalse($wrongPassword->isLocked());
        assertNull($wrongPassword->admin());
        assertSame(1, $this->throttle->failureCalls);
    }

    /**
     * A correct password against a deactivated account still fails, and fails the same generic way, so
     * disabling an admin is effective and does not leak that the account exists.
     */
    public function testDeactivatedAccountCannotLogInEvenWithTheRightPassword(): void
    {
        $this->users->add('admin', $this->hasher->hash(self::PASSWORD), isActive: false);

        $result = $this->service->login('admin', self::PASSWORD, self::KEY);

        assertFalse($result->isSuccess());
        assertSame(0, $this->identity->storeCalls);
        assertSame(1, $this->throttle->failureCalls);
    }

    public function testLockedThrottleShortCircuitsBeforeAnyCredentialCheck(): void
    {
        $this->users->add('admin', $this->hasher->hash(self::PASSWORD));
        $this->throttle->lock(300);

        $result = $this->service->login('admin', self::PASSWORD, self::KEY);

        assertTrue($result->isLocked());
        assertSame(300, $result->retryAfterSeconds());
        // Even correct credentials must not authenticate while locked, and no new failure is counted.
        assertSame(0, $this->identity->storeCalls);
        assertSame(0, $this->throttle->failureCalls);
    }

    /**
     * A hash weaker than the current default is transparently replaced on a successful login — the
     * PASSWORD_DEFAULT + needs_rehash upgrade path the plan requires.
     */
    public function testWeakHashIsUpgradedOnSuccessfulLogin(): void
    {
        $weakHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->users->add('admin', $weakHash);

        $result = $this->service->login('admin', self::PASSWORD, self::KEY);

        assertTrue($result->isSuccess());
        assertSame(1, $this->users->updatePasswordCalls, 'weak hash must be rehashed');
    }

    public function testCurrentHashIsNotRehashed(): void
    {
        $this->users->add('admin', $this->hasher->hash(self::PASSWORD));

        $this->service->login('admin', self::PASSWORD, self::KEY);

        assertSame(0, $this->users->updatePasswordCalls);
    }
}
