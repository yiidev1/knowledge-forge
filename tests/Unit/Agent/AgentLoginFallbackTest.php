<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\AgentLoginService;
use App\Agent\Application\FallbackAgentAuthenticator;
use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\TrustedAgentLookupResult;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58ErrorDetails;
use App\Order58\Contract\Dto\Order58ValidationOutcome;
use App\Order58\Contract\Exception\Order58AuthenticationFailed;
use App\Order58\Contract\Exception\Order58InvalidResponse;
use App\Order58\Contract\Exception\Order58Timeout;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Tests\Support\Fake\Agent\InMemoryAgentIdentityStore;
use App\Tests\Support\Fake\Agent\InMemoryAgentLoginActivityRepository;
use App\Tests\Support\Fake\Agent\InMemoryTrustedAgentDirectory;
use App\Tests\Support\Fake\Auth\InMemoryLoginThrottle;
use App\Tests\Support\Fake\Order58\FakeOrder58Client;
use App\Tests\Support\Fake\Order58\FakeOrder58CredentialValidator;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The fallback credential path.
 *
 * Three properties are worth more than the rest, and most of this file exists to pin them:
 *
 * 1. **The fallback is unreachable except from an explicit credential rejection.** A non-agent, an inactive
 *    account and every transport fault must never be given a second chance to answer differently.
 * 2. **A valid password is not an identity.** The validate API confirms credentials for admins and merchants
 *    too; only a single fresh `user_type=agent`/`status=active` row may be admitted.
 * 3. **A failure to ask is not a wrong password.** An outage must cost the sender nothing on the throttle,
 *    or a broken integration would lock legitimate agents out of their own accounts.
 */
final class AgentLoginFallbackTest extends Unit
{
    private const AGENT_ID = 139;

    private FakeOrder58Client $client;
    private FakeOrder58CredentialValidator $validator;
    private InMemoryTrustedAgentDirectory $trustedAgents;
    private InMemoryAgentIdentityStore $identityStore;
    private InMemoryLoginThrottle $throttle;
    private InMemoryAgentLoginActivityRepository $activity;
    private MutableClock $clock;
    private AgentLoginService $service;

    protected function _before(): void
    {
        $this->client = new FakeOrder58Client();
        $this->validator = new FakeOrder58CredentialValidator();
        $this->trustedAgents = new InMemoryTrustedAgentDirectory();
        $this->identityStore = new InMemoryAgentIdentityStore();
        $this->throttle = new InMemoryLoginThrottle();
        $this->clock = new MutableClock();
        $this->activity = new InMemoryAgentLoginActivityRepository($this->clock);

        $this->service = new AgentLoginService(
            $this->client,
            $this->identityStore,
            $this->throttle,
            $this->activity,
            new NullLogger(),
            new FallbackAgentAuthenticator(
                $this->validator,
                $this->trustedAgents,
                $this->clock,
                new NullLogger(),
                72,
            ),
        );
    }

    // ---------------------------------------------------------------- the fallback must not be reachable

    public function testPrimarySuccessNeverConsultsTheFallback(): void
    {
        $this->client->authResult = Order58AuthResult::success($this->agent());

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertSame(0, $this->validator->calls(), 'a working primary login must not touch the fallback');
        assertSame([], $this->trustedAgents->lookups);
        assertSame(self::AGENT_ID, $result->agent?->adminId);
        assertSame(1, $this->activity->count());
    }

    public function testNonAgentIsRejectedWithoutConsultingTheFallback(): void
    {
        // The primary identified them. That decision is final — a second opinion could only weaken it.
        $this->client->authResult = Order58AuthResult::success($this->agent(userType: 'merchant'));
        $this->trustedAgents->willFind();

        $result = $this->service->login('boss', 'secret', 'key');

        assertFalse($result->success);
        assertSame(0, $this->validator->calls());
        assertNull($this->identityStore->stored);
        assertSame(1, $this->throttle->failureCalls);
    }

    public function testInactiveAgentIsRejectedWithoutConsultingTheFallback(): void
    {
        $this->client->authResult = Order58AuthResult::success($this->agent(status: 'disable'));
        $this->trustedAgents->willFind();

        $result = $this->service->login('agent', 'secret', 'key');

        assertFalse($result->success);
        assertSame(0, $this->validator->calls());
        assertNull($this->identityStore->stored);
    }

    /** @dataProvider primaryInfrastructureFailures */
    public function testPrimaryInfrastructureFailureNeverConsultsTheFallback(string $exceptionClass, string $code): void
    {
        $this->client->authException = new $exceptionClass(Order58ErrorDetails::of($code, 'boom', 500, true));

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->unavailable);
        assertSame(0, $this->validator->calls(), 'an outage must not divert traffic onto the weaker path');
        assertSame(0, $this->throttle->failureCalls);
        assertNull($this->identityStore->stored);
    }

    public function primaryInfrastructureFailures(): array
    {
        return [
            'timeout / DNS / TLS' => [Order58Timeout::class, 'network_error'],
            'server error' => [Order58TransportFailed::class, 'server_error'],
            'our bearer token' => [Order58AuthenticationFailed::class, 'auth_failed'],
            'malformed response' => [Order58InvalidResponse::class, 'invalid_response'],
        ];
    }

    public function testLockedThrottleCallsNeitherApi(): void
    {
        $this->throttle->lock(120);

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->locked);
        assertSame([], $this->client->authenticatedUsernames);
        assertSame(0, $this->validator->calls());
    }

    // ---------------------------------------------------------------- the happy fallback path

    public function testFallbackValidWithOneFreshActiveAgentLogsIn(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $identity = $this->trustedAgents->willFind(self::AGENT_ID, 'agent');

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertSame($identity, $result->agent);
        assertSame($identity, $this->identityStore->stored, 'the ordinary session identity, not a new shape');
        assertSame(['agent'], $this->validator->logins);
        assertSame(['agent'], $this->trustedAgents->lookups, 'resolution keys on the entered username');
        assertSame(1, $this->throttle->clearCalls);
        assertSame(0, $this->throttle->failureCalls);
    }

    public function testFallbackLoginRecordsLoginActivityThroughTheNormalPath(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willFind(self::AGENT_ID, 'agent');

        $this->service->login('agent', 'secret', 'key');

        $row = $this->activity->findByAgent(self::AGENT_ID);
        assertNotNull($row);
        assertSame(self::AGENT_ID, $row->agentAdminId);
        assertSame(1, $row->loginCount);
    }

    public function testTrackingFailureStillDoesNotBlockAFallbackLogin(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willFind();
        $this->activity->failWith = InMemoryAgentLoginActivityRepository::unavailable();

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertNotNull($this->identityStore->stored);
        assertSame(0, $this->activity->count());
    }

    public function testSessionAfterFallbackLoginCarriesNoSecretOrEmployerAccountId(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willFind();

        $this->service->login('agent', 'SuperSecret', 'key');

        $data = $this->identityStore->stored?->toArray() ?? [];
        assertSame(
            ['admin_id', 'username', 'display_name', 'email', 'status', 'user_type'],
            array_keys($data),
            'exactly the six safe identity keys',
        );
        assertArrayNotHasKey('password', $data);
        assertArrayNotHasKey('account_id', $data);
        assertArrayNotHasKey('message', $data);
    }

    public function testTheFreshnessWindowComesFromTheConfiguredAge(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willFind();

        $this->service->login('agent', 'secret', 'key');

        assertSame(
            $this->clock->now()->modify('-72 hours')->format('Y-m-d H:i:s'),
            $this->trustedAgents->lastNotSyncedBefore?->format('Y-m-d H:i:s'),
        );
    }

    // ---------------------------------------------------------------- a valid password is not an identity

    /**
     * The judy case, from real mirror data: valid credentials belonging to a `user_type = 'admin'` account.
     * The validate API confirms her password; the agent realm must still refuse her.
     *
     * @dataProvider unresolvableIdentities
     */
    public function testValidCredentialsWithNoResolvableAgentAreRejected(TrustedAgentLookupResult $lookup): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willReturn($lookup);

        $result = $this->service->login('judy', 'correct-password', 'key');

        assertFalse($result->success);
        assertFalse($result->unavailable, 'a resolvable-identity failure is a credential verdict, not an outage');
        assertNull($this->identityStore->stored);
        assertNull($result->message);
        assertSame(1, $this->throttle->failureCalls);
        assertSame(0, $this->activity->count());
    }

    public function unresolvableIdentities(): array
    {
        return [
            'no active agent (admin, merchant, unsynced)' => [TrustedAgentLookupResult::notFound()],
            'two agents share the username' => [TrustedAgentLookupResult::ambiguous()],
            'mirror row too old to trust' => [TrustedAgentLookupResult::stale()],
        ];
    }

    // ---------------------------------------------------------------- rejection vs outage

    public function testBothApisRejectingIsOneThrottleFailureAndTheGenericMessage(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::CredentialsRejected;

        $result = $this->service->login('agent', 'wrong', 'key');

        assertFalse($result->success);
        assertFalse($result->unavailable);
        assertNull($result->message, 'a wrong password never surfaces provider wording');
        assertNull($this->identityStore->stored);
        assertSame(1, $this->throttle->failureCalls, 'exactly one failure per submitted password');
        assertSame([], $this->trustedAgents->lookups, 'a rejected password is never looked up locally');
    }

    /** @dataProvider fallbackInfrastructureFailures */
    public function testFallbackInfrastructureFailureIsUnavailableAndCostsNoThrottleFailure(
        Order58ValidationOutcome $outcome,
    ): void {
        $this->primaryRejects();
        $this->validator->outcome = $outcome;

        $result = $this->service->login('agent', 'secret', 'key');

        assertFalse($result->success);
        assertTrue($result->unavailable);
        assertNull($this->identityStore->stored);
        assertSame(0, $this->throttle->failureCalls, 'we never got a verdict, so nothing may be charged');
        assertSame([], $this->trustedAgents->lookups, 'no identity lookup without a valid credential');
    }

    public function fallbackInfrastructureFailures(): array
    {
        return [
            'timeout / DNS / TLS' => [Order58ValidationOutcome::NetworkError],
            'upstream 5xx' => [Order58ValidationOutcome::UpstreamError],
            'our bearer token refused' => [Order58ValidationOutcome::AuthFailed],
            'malformed or unusable body' => [Order58ValidationOutcome::InvalidResponse],
            'not configured' => [Order58ValidationOutcome::NotConfigured],
        ];
    }

    // ---------------------------------------------------------------- user-facing message

    public function testAnUpstreamMessageIsSurfacedOnlyForANonCredentialFailure(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::UpstreamError;
        $this->validator->safeMessage = 'Some API error';

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->unavailable);
        assertSame('Some API error', $result->message);
    }

    public function testAnUpstreamMessageIsNeverAttachedToACredentialVerdict(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::CredentialsRejected;
        $this->validator->safeMessage = 'Bad Request';

        $result = $this->service->login('agent', 'wrong', 'key');

        assertFalse($result->unavailable);
        assertNull($result->message, 'the generic wording is used, never the provider text');
    }

    public function testAMissingUpstreamMessageFallsBackToTheGenericUnavailableResult(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::UpstreamError;
        $this->validator->safeMessage = null;

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->unavailable);
        assertNull($result->message, 'the action then prints its own generic sentence');
    }

    public function testSuccessMessageIsNeverCarriedIntoTheResult(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->validator->safeMessage = 'SUCCESS';
        $this->trustedAgents->willFind();

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->success);
        assertNull($result->message, '"SUCCESS" must never reach the user');
    }

    // ---------------------------------------------------------------- helpers

    private function primaryRejects(): void
    {
        $this->client->authResult = Order58AuthResult::invalid();
    }

    private function agent(string $userType = 'agent', string $status = 'active'): Order58Agent
    {
        return new Order58Agent(
            self::AGENT_ID,
            'agent',
            'Agent',
            'One',
            'agent@test.com',
            null,
            '1',
            $status,
            $userType,
            21,
            null,
            '',
            ['admin_id' => self::AGENT_ID, 'account_id' => 21],
        );
    }

    /** Guards the identity contract the fallback has to reproduce exactly. */
    public function testFallbackIdentityIsTheSameTypeThePrimaryProduces(): void
    {
        $this->primaryRejects();
        $this->validator->outcome = Order58ValidationOutcome::Valid;
        $this->trustedAgents->willFind();

        $result = $this->service->login('agent', 'secret', 'key');

        assertTrue($result->agent instanceof AgentIdentity);
    }
}
