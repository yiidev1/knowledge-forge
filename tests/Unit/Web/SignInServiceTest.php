<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Agent\Application\AgentLoginService;
use App\Agent\Application\FallbackAgentAuthenticator;
use App\Agent\Domain\AgentIdentity;
use App\Auth\Application\LoginService;
use App\Auth\Application\LoginThrottleInterface;
use App\Auth\Application\ThrottleKey;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58ErrorDetails;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Tests\Support\Fake\Agent\InMemoryAgentIdentityStore;
use App\Tests\Support\Fake\Agent\InMemoryAgentLoginActivityRepository;
use App\Tests\Support\Fake\Agent\InMemoryTrustedAgentDirectory;
use App\Tests\Support\Fake\Auth\InMemoryAdminUserRepository;
use App\Tests\Support\Fake\Auth\InMemoryIdentityStore;
use App\Tests\Support\Fake\Order58\FakeOrder58Client;
use App\Tests\Support\Fake\Order58\FakeOrder58CredentialValidator;
use App\Tests\Support\MutableClock;
use App\Web\SignIn\SignInService;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The one sign-in form: which existing login a username reaches, and what that may never allow.
 *
 * The two logins it delegates to are the real services, over the same fakes their own tests use — so what
 * is pinned here is the routing between them and the session tidying, not a second copy of either.
 *
 * The cases that matter most are the negative ones: an administrator's password is never posted to
 * Order58 unless Order58 really has an account of that name, nothing reaches the administrator area
 * without the administrator's own password, and a successful sign-in never leaves the other realm's
 * identity behind.
 */
final class SignInServiceTest extends Unit
{
    private const IP = '203.0.113.7';
    private const ADMIN_PASSWORD = 'correct horse battery staple';

    private InMemoryAdminUserRepository $admins;
    private InMemoryIdentityStore $adminSession;
    private InMemoryAgentIdentityStore $order58Session;
    private FakeOrder58Client $order58;
    private InMemoryTrustedAgentDirectory $order58Accounts;
    private KeyedThrottle $adminThrottle;
    private KeyedThrottle $order58Throttle;
    private NativePasswordHasher $hasher;
    private SignInService $signIn;

    protected function _before(): void
    {
        $this->admins = new InMemoryAdminUserRepository();
        $this->adminSession = new InMemoryIdentityStore();
        $this->order58Session = new InMemoryAgentIdentityStore();
        $this->order58 = new FakeOrder58Client();
        $this->order58Accounts = new InMemoryTrustedAgentDirectory();
        $this->adminThrottle = new KeyedThrottle();
        $this->order58Throttle = new KeyedThrottle();
        $this->hasher = new NativePasswordHasher();

        $this->signIn = new SignInService(
            $this->admins,
            new LoginService($this->admins, $this->hasher, $this->adminSession, $this->adminThrottle),
            new AgentLoginService(
                $this->order58,
                $this->order58Session,
                $this->order58Throttle,
                new InMemoryAgentLoginActivityRepository(new MutableClock()),
                new NullLogger(),
                new FallbackAgentAuthenticator(
                    new FakeOrder58CredentialValidator(),
                    // The fallback's own directory: empty, so the fallback never admits anybody here.
                    new InMemoryTrustedAgentDirectory(),
                    new MutableClock(),
                    new NullLogger(),
                    72,
                ),
            ),
            $this->order58Accounts,
            $this->adminSession,
            $this->order58Session,
        );
    }

    // ------------------------------------------------------------------ administrators

    public function testAnAdministratorSignsInToTheDashboard(): void
    {
        $this->admins->add('boss', $this->hasher->hash(self::ADMIN_PASSWORD));

        $result = $this->signIn->signIn('boss', self::ADMIN_PASSWORD, self::IP);

        assertSame('dashboard', $result->homeRoute);
        assertSame(1, $this->adminSession->storeCalls);
        assertSame([], $this->order58->authenticatedUsernames, 'an administrator is never sent to Order58');
    }

    /** A mistyped administrator password is a near-miss of a real one, and is not posted to a third party. */
    public function testAWrongAdministratorPasswordIsNeverSentToOrder58(): void
    {
        $this->admins->add('boss', $this->hasher->hash(self::ADMIN_PASSWORD));

        $result = $this->signIn->signIn('boss', 'correct horse battery stapler', self::IP);

        assertFalse($result->isSuccess());
        assertFalse($result->isLocked());
        assertSame([], $this->order58->authenticatedUsernames);
        // Counted where the administrator page always counted it.
        assertSame([ThrottleKey::for('boss', self::IP)], $this->adminThrottle->failures);
    }

    public function testAnInactiveAdministratorIsRefusedAndNotSentToOrder58(): void
    {
        $this->admins->add('boss', $this->hasher->hash(self::ADMIN_PASSWORD), isActive: false);

        assertFalse($this->signIn->signIn('boss', self::ADMIN_PASSWORD, self::IP)->isSuccess());
        assertSame(0, $this->adminSession->storeCalls);
        assertSame([], $this->order58->authenticatedUsernames);
    }

    public function testALockedAdministratorIsToldOfTheLock(): void
    {
        $this->admins->add('boss', $this->hasher->hash(self::ADMIN_PASSWORD));
        $this->adminThrottle->lockKey(ThrottleKey::for('boss', self::IP), 600);

        $result = $this->signIn->signIn('boss', self::ADMIN_PASSWORD, self::IP);

        assertTrue($result->isLocked());
        assertSame(600, $result->retryAfterSeconds);
        assertSame([], $this->order58->authenticatedUsernames);
    }

    // ------------------------------------------------------------------ agents and operation users

    public function testAnAgentSignsInToTheAgentApp(): void
    {
        $this->order58->authResult = Order58AuthResult::success($this->order58User('terese', 'agent'));

        $result = $this->signIn->signIn('terese', 'their password', self::IP);

        assertSame('agent.home', $result->homeRoute);
        assertSame('terese', $this->order58Session->stored?->username);
        // Counted where the agent page always counted it, and never in the administrators' bucket.
        assertSame([], $this->adminThrottle->failures);
        assertSame([ThrottleKey::for('order58-agent:terese', self::IP)], $this->order58Throttle->cleared);
    }

    public function testAnOperationUserSignsInToOrderTesting(): void
    {
        $this->order58->authResult = Order58AuthResult::success($this->order58User('ops', 'operation'));

        assertSame('order-testing', $this->signIn->signIn('ops', 'their password', self::IP)->homeRoute);
    }

    /** Order58's own allow-list still applies: a merchant is refused exactly as on the agent page. */
    public function testAnOrder58AccountOfAnotherTypeIsRefused(): void
    {
        $this->order58->authResult = Order58AuthResult::success($this->order58User('shop', 'merchant'));

        $result = $this->signIn->signIn('shop', 'their password', self::IP);

        assertFalse($result->isSuccess());
        assertNull($this->order58Session->stored);
        assertSame(0, $this->adminSession->storeCalls);
    }

    public function testAnUnknownUsernameIsInvalid(): void
    {
        $result = $this->signIn->signIn('nobody', 'whatever', self::IP);

        assertFalse($result->isSuccess());
        assertFalse($result->isLocked());
        assertFalse($result->unavailable);
    }

    public function testAnOrder58OutageSaysSo(): void
    {
        $this->order58->authException = new Order58TransportFailed(Order58ErrorDetails::of('server_error', 'down', 500, true));

        assertTrue($this->signIn->signIn('terese', 'their password', self::IP)->unavailable);
    }

    // ------------------------------------------------------------------ one name, two accounts

    /** The administrator's own password reaches the administrator area, and Order58 is not asked. */
    public function testASharedUsernameWithTheAdministratorPasswordIsTheAdministrator(): void
    {
        $this->admins->add('shared', $this->hasher->hash(self::ADMIN_PASSWORD));
        $this->order58Accounts->willFind(139, 'shared');

        assertSame('dashboard', $this->signIn->signIn('shared', self::ADMIN_PASSWORD, self::IP)->homeRoute);
        assertSame([], $this->order58->authenticatedUsernames);
    }

    /** The Order58 account's password reaches only that account's area — never the administrator's. */
    public function testASharedUsernameWithTheOrder58PasswordIsTheOrder58User(): void
    {
        $this->admins->add('shared', $this->hasher->hash(self::ADMIN_PASSWORD));
        $this->order58Accounts->willFind(139, 'shared');
        $this->order58->authResult = Order58AuthResult::success($this->order58User('shared', 'agent'));

        $result = $this->signIn->signIn('shared', 'the order58 password', self::IP);

        assertSame('agent.home', $result->homeRoute);
        assertSame(0, $this->adminSession->storeCalls, 'an Order58 password must never open the admin area');
        assertSame(['shared'], $this->order58->authenticatedUsernames);
    }

    public function testASharedUsernameWithNeitherPasswordIsInvalid(): void
    {
        $this->admins->add('shared', $this->hasher->hash(self::ADMIN_PASSWORD));
        $this->order58Accounts->willFind(139, 'shared');

        $result = $this->signIn->signIn('shared', 'neither', self::IP);

        assertFalse($result->isSuccess());
        assertSame(0, $this->adminSession->storeCalls);
        assertNull($this->order58Session->stored);
    }

    // ------------------------------------------------------------------ one realm at a time

    public function testSigningInAsAnAdministratorDropsAnOrder58Identity(): void
    {
        $this->order58Session->store($this->identity());
        $this->admins->add('boss', $this->hasher->hash(self::ADMIN_PASSWORD));

        $this->signIn->signIn('boss', self::ADMIN_PASSWORD, self::IP);

        assertNull($this->order58Session->current());
    }

    public function testSigningInAsAnOrder58UserDropsAnAdministratorIdentity(): void
    {
        $this->adminSession->store(7);
        $this->order58->authResult = Order58AuthResult::success($this->order58User('terese', 'agent'));

        $this->signIn->signIn('terese', 'their password', self::IP);

        assertNull($this->adminSession->currentId());
    }

    /** A failed attempt signs nobody out. */
    public function testAFailedSignInLeavesTheCurrentIdentityAlone(): void
    {
        $this->adminSession->store(7);

        $this->signIn->signIn('nobody', 'whatever', self::IP);

        assertSame(7, $this->adminSession->currentId());
        assertSame(0, $this->adminSession->clearCalls);
    }

    // ------------------------------------------------------------------ harness

    private function order58User(string $username, string $userType): Order58Agent
    {
        return new Order58Agent(
            139,
            $username,
            $username,
            null,
            null,
            null,
            null,
            'active',
            $userType,
            2,
            null,
            '',
            ['admin_id' => 139, 'account_id' => 2],
        );
    }

    private function identity(): AgentIdentity
    {
        return AgentIdentity::fromAgent($this->order58User('terese', 'agent'));
    }
}

/** A throttle that keeps its keys apart, so a test can see which bucket each login counted in. */
final class KeyedThrottle implements LoginThrottleInterface
{
    /** @var list<string> */
    public array $failures = [];

    /** @var list<string> */
    public array $cleared = [];

    /** @var array<string, int> */
    private array $locked = [];

    public function lockKey(string $key, int $retryAfterSeconds): void
    {
        $this->locked[$key] = $retryAfterSeconds;
    }

    public function retryAfterSeconds(string $key): ?int
    {
        return $this->locked[$key] ?? null;
    }

    public function registerFailure(string $key): void
    {
        $this->failures[] = $key;
    }

    public function clear(string $key): void
    {
        $this->cleared[] = $key;
    }
}
