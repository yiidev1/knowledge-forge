<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\AgentLoginService;
use App\Agent\Application\FallbackAgentAuthenticator;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58ErrorDetails;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Tests\Support\Fake\Agent\InMemoryAgentIdentityStore;
use App\Tests\Support\Fake\Agent\InMemoryTrustedAgentDirectory;
use App\Tests\Support\Fake\Agent\InMemoryAgentLoginActivityRepository;
use App\Tests\Support\Fake\Auth\InMemoryLoginThrottle;
use App\Tests\Support\Fake\Order58\FakeOrder58Client;
use App\Tests\Support\Fake\Order58\FakeOrder58CredentialValidator;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Local sign-in tracking, driven through the real {@see AgentLoginService}.
 *
 * The two properties worth protecting: a successful login leaves exactly one row per agent that accumulates
 * rather than duplicates, and nothing about tracking can prevent an agent from signing in.
 */
final class AgentLoginActivityTest extends Unit
{
    private const AGENT_ID = 139;

    private FakeOrder58Client $client;
    private InMemoryAgentIdentityStore $identityStore;
    private InMemoryLoginThrottle $throttle;
    private InMemoryAgentLoginActivityRepository $activity;
    private MutableClock $clock;
    private FakeOrder58CredentialValidator $fallbackValidator;
    private InMemoryTrustedAgentDirectory $trustedAgents;
    private AgentLoginService $service;

    protected function _before(): void
    {
        $this->client = new FakeOrder58Client();
        $this->identityStore = new InMemoryAgentIdentityStore();
        $this->throttle = new InMemoryLoginThrottle();
        $this->clock = new MutableClock();
        $this->activity = new InMemoryAgentLoginActivityRepository($this->clock);
        $this->fallbackValidator = new FakeOrder58CredentialValidator();
        $this->trustedAgents = new InMemoryTrustedAgentDirectory();
        $this->service = new AgentLoginService(
            $this->client,
            $this->identityStore,
            $this->throttle,
            $this->activity,
            new NullLogger(),
            $this->fallbackAuthenticator(),
        );
    }

    // ------------------------------------------------------------------ first login

    public function testFirstSuccessfulLoginCreatesOneRowWithBothTimestampsAndCountOne(): void
    {
        $this->succeedWith($this->agent());
        $this->service->login('agent', 'secret', 'key');

        assertSame(1, $this->activity->count());

        $row = $this->activity->findByAgent(self::AGENT_ID);
        assertNotNull($row);
        assertSame(self::AGENT_ID, $row->agentAdminId);
        assertSame(1, $row->loginCount);
        assertSame(
            $this->clock->now()->format('Y-m-d H:i:s'),
            $row->firstLoginAt->format('Y-m-d H:i:s'),
        );
        assertSame(
            $row->firstLoginAt->format('Y-m-d H:i:s'),
            $row->lastLoginAt->format('Y-m-d H:i:s'),
            'on the very first login the two timestamps are the same moment',
        );
        // The snapshot is there so the table reads without joining the Order58 mirror.
        assertSame('agent', $row->username);
    }

    // ------------------------------------------------------------------ repeat login

    public function testSecondLoginUpdatesTheSameRowPreservingFirstLoginAt(): void
    {
        $this->succeedWith($this->agent());
        $this->service->login('agent', 'secret', 'key');
        $firstAt = $this->activity->findByAgent(self::AGENT_ID)?->firstLoginAt;
        assertNotNull($firstAt);

        $this->clock->advance('+3 days');
        $this->service->login('agent', 'secret', 'key');

        $row = $this->activity->findByAgent(self::AGENT_ID);
        assertNotNull($row);

        // One row, two writes — an update, not a second insert.
        assertSame(1, $this->activity->count());
        assertCount(2, $this->activity->writes);

        assertSame($firstAt->format('Y-m-d H:i:s'), $row->firstLoginAt->format('Y-m-d H:i:s'));
        assertSame($this->clock->now()->format('Y-m-d H:i:s'), $row->lastLoginAt->format('Y-m-d H:i:s'));
        assertTrue($row->lastLoginAt > $row->firstLoginAt);
        assertSame(2, $row->loginCount);
        assertTrue($row->isReturning());
    }

    public function testCountKeepsAccumulatingAcrossManyLogins(): void
    {
        $this->succeedWith($this->agent());
        for ($i = 0; $i < 7; $i++) {
            $this->clock->advance('+1 hour');
            $this->service->login('agent', 'secret', 'key');
        }

        assertSame(7, $this->activity->findByAgent(self::AGENT_ID)?->loginCount);
        assertSame(1, $this->activity->count());
    }

    public function testDifferentAgentsGetDifferentRows(): void
    {
        $this->succeedWith($this->agent());
        $this->service->login('agent', 'secret', 'key');

        $this->succeedWith($this->agent(adminId: 777, username: 'other'));
        $this->service->login('other', 'secret', 'key');

        assertSame(2, $this->activity->count());
        assertSame(1, $this->activity->findByAgent(self::AGENT_ID)?->loginCount);
        assertSame(1, $this->activity->findByAgent(777)?->loginCount);
    }

    // ------------------------------------------------------------------ failures record nothing

    public function testFailedAuthenticationRecordsNothing(): void
    {
        $this->client->authResult = Order58AuthResult::invalid();
        $this->service->login('agent', 'wrong', 'key');

        assertSame(0, $this->activity->count());
        assertNull($this->activity->findByAgent(self::AGENT_ID));
    }

    public function testRejectedNonAgentAndInactiveAccountsRecordNothing(): void
    {
        $this->succeedWith($this->agent(userType: 'merchant'));
        $this->service->login('agent', 'secret', 'key');

        $this->succeedWith($this->agent(status: 'disabled'));
        $this->service->login('agent', 'secret', 'key');

        assertSame(0, $this->activity->count());
    }

    public function testTransportFailureRecordsNothing(): void
    {
        $this->client->authException = new Order58TransportFailed(
            Order58ErrorDetails::of('server_error', 'boom', 500, true),
        );
        $this->service->login('agent', 'secret', 'key');

        assertSame(0, $this->activity->count());
    }

    public function testLockedLoginRecordsNothing(): void
    {
        $this->throttle->lock(120);
        $this->service->login('agent', 'secret', 'key');

        assertSame(0, $this->activity->count());
    }

    // ------------------------------------------------------------------ resilience & safety

    /**
     * The agent has already authenticated and their session already exists by the time this runs — losing a
     * usage row must never cost them access.
     */
    public function testTrackingFailureDoesNotPreventLogin(): void
    {
        $this->succeedWith($this->agent());
        $this->activity->failWith = InMemoryAgentLoginActivityRepository::unavailable();

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertNotNull($result->agent);
        assertNotNull($this->identityStore->stored, 'the session identity is still established');
        assertSame(1, $this->throttle->clearCalls, 'the throttle is still cleared');
        assertSame(0, $this->activity->count());
    }

    /**
     * The row may only ever carry the safe identity. `account_id` is on the upstream DTO and must not leak,
     * and the password is never anywhere near this path.
     */
    public function testNoCredentialOrEmployerAccountIdIsPersisted(): void
    {
        $this->succeedWith($this->agent());
        $this->service->login('agent', 'SuperSecret', 'key');

        $row = $this->activity->findByAgent(self::AGENT_ID);
        assertNotNull($row);

        $persisted = [
            $row->username,
            $row->displayName,
            (string) $row->agentAdminId,
            (string) $row->loginCount,
            $row->firstLoginAt->format('c'),
            $row->lastLoginAt->format('c'),
        ];

        foreach ($persisted as $value) {
            assertTrue(
                !str_contains($value, 'SuperSecret') && $value !== '2',
                'no credential and no account_id may appear in a stored field: ' . $value,
            );
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The fallback collaborator, wired to reject by default so every pre-existing assertion in this file
     * still describes the primary path alone. The fallback's own matrix lives in AgentLoginFallbackTest.
     */
    private function fallbackAuthenticator(): FallbackAgentAuthenticator
    {
        return new FallbackAgentAuthenticator(
            $this->fallbackValidator,
            $this->trustedAgents,
            new MutableClock(),
            new NullLogger(),
            72,
        );
    }

    private function succeedWith(Order58Agent $agent): void
    {
        $this->client->authResult = Order58AuthResult::success($agent);
        $this->client->authException = null;
    }

    private function agent(
        string $userType = 'agent',
        string $status = 'active',
        int $adminId = self::AGENT_ID,
        string $username = 'agent',
    ): Order58Agent {
        return new Order58Agent(
            $adminId,
            $username,
            'agent',
            '1',
            'agent@test.com',
            null,
            '1',
            $status,
            $userType,
            2,
            null,
            '',
            ['admin_id' => $adminId, 'account_id' => 2],
        );
    }
}
