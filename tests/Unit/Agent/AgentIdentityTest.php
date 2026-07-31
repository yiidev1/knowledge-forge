<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Domain\AgentIdentity;
use App\Order58\Contract\Dto\Order58Agent;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * The safe agent identity: only non-sensitive fields, a sensible display name, and a session round-trip
 * that carries nothing that could authorize store access.
 */
final class AgentIdentityTest extends Unit
{
    public function testFromAgentKeepsSafeFieldsAndComputesDisplayName(): void
    {
        $agent = new Order58Agent(139, 'agent', 'Jane', 'Doe', 'jane@test.com', null, '1', 'active', 'agent', 2, null, '', []);

        $identity = AgentIdentity::fromAgent($agent);

        assertSame(139, $identity->adminId);
        assertSame('Jane Doe', $identity->displayName);
        assertSame('agent', $identity->userType);
        assertSame('active', $identity->status);
    }

    public function testDisplayNameFallsBackToUsername(): void
    {
        $agent = new Order58Agent(1, 'wonderful', null, null, null, null, null, 'active', 'agent', 1, null, '', []);

        assertSame('wonderful', AgentIdentity::fromAgent($agent)->displayName);
    }

    public function testRoundTripsThroughSessionArrayWithoutSensitiveData(): void
    {
        $data = (new AgentIdentity(139, 'agent', 'Jane Doe', 'jane@test.com', 'active', 'agent'))->toArray();

        assertArrayNotHasKey('password', $data);
        assertArrayNotHasKey('account_id', $data);

        $restored = AgentIdentity::fromArray($data);
        assertSame(139, $restored?->adminId);
        assertSame('agent', $restored?->userType);
    }

    public function testMalformedSessionDataYieldsNull(): void
    {
        assertNull(AgentIdentity::fromArray([]));
        assertNull(AgentIdentity::fromArray(['username' => 'x']));
    }
}
