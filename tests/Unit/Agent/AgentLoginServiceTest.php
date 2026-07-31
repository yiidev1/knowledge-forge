<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\AgentLoginService;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58ErrorDetails;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Tests\Support\Fake\Agent\InMemoryAgentIdentityStore;
use App\Tests\Support\Fake\Auth\InMemoryLoginThrottle;
use App\Tests\Support\Fake\Order58\FakeOrder58Client;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The agent login gate: only an authenticated `user_type = agent` with `status = active` is admitted, the
 * password is never persisted, and a service error is distinguished from a bad password.
 */
final class AgentLoginServiceTest extends Unit
{
    private FakeOrder58Client $client;
    private InMemoryAgentIdentityStore $identityStore;
    private InMemoryLoginThrottle $throttle;
    private AgentLoginService $service;

    protected function _before(): void
    {
        $this->client = new FakeOrder58Client();
        $this->identityStore = new InMemoryAgentIdentityStore();
        $this->throttle = new InMemoryLoginThrottle();
        $this->service = new AgentLoginService($this->client, $this->identityStore, $this->throttle);
    }

    private function agent(string $userType = 'agent', string $status = 'active'): Order58Agent
    {
        return new Order58Agent(139, 'agent', 'agent', '1', 'agent@test.com', null, '1', $status, $userType, 2, null, '', ['admin_id' => 139, 'account_id' => 2]);
    }

    public function testSuccessfulLoginStoresSafeIdentityAndClearsThrottle(): void
    {
        $this->client->authResult = Order58AuthResult::success($this->agent());

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertSame(139, $result->agent?->adminId);
        assertSame($this->identityStore->stored, $result->agent);
        assertSame(1, $this->throttle->clearCalls);
        assertSame(0, $this->throttle->failureCalls);
    }

    public function testRejectsWrongCredentials(): void
    {
        $this->client->authResult = Order58AuthResult::invalid();

        $result = $this->service->login('agent', 'wrong', 'key');

        assertFalse($result->success);
        assertNull($this->identityStore->stored);
        assertSame(1, $this->throttle->failureCalls);
    }

    public function testRejectsNonAgentUserType(): void
    {
        // Authenticated, but a merchant — must be refused with the same generic result as a wrong password.
        $this->client->authResult = Order58AuthResult::success($this->agent(userType: 'merchant'));

        $result = $this->service->login('boss', 'secret', 'key');

        assertFalse($result->success);
        assertNull($this->identityStore->stored);
        assertSame(1, $this->throttle->failureCalls);
    }

    public function testRejectsInactiveAgent(): void
    {
        $this->client->authResult = Order58AuthResult::success($this->agent(status: 'disabled'));

        $result = $this->service->login('agent', 'secret', 'key');

        assertFalse($result->success);
        assertNull($this->identityStore->stored);
    }

    public function testLockedShortCircuitsWithoutCallingTheApi(): void
    {
        $this->throttle->lock(120);

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->locked);
        assertSame(120, $result->retryAfterSeconds);
        assertSame([], $this->client->authenticatedUsernames, 'a locked login must not reach Order58');
    }

    public function testServiceErrorIsUnavailableAndNotCountedAsAFailure(): void
    {
        $this->client->authException = new Order58TransportFailed(Order58ErrorDetails::of('server_error', 'boom', 500, true));

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->unavailable);
        assertNull($this->identityStore->stored);
        assertSame(0, $this->throttle->failureCalls);
    }

    public function testStoredIdentityCarriesNoPasswordOrEmployerAccountId(): void
    {
        $this->client->authResult = Order58AuthResult::success($this->agent());

        $this->service->login('agent', 'SuperSecret', 'key');

        $data = $this->identityStore->stored?->toArray() ?? [];
        assertArrayNotHasKey('password', $data);
        assertArrayNotHasKey('account_id', $data);
    }
}
