<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sort;
use function str_contains;
use function substr_count;

/**
 * The exact list of addresses the agent practice surface mounts.
 *
 * ## Why read the route file rather than the router
 *
 * Booting the container to enumerate routes would prove the same thing more slowly and would make a
 * unit test depend on the whole application. What is being guarded is a *decision*: this list and no
 * other. Reading the declarations and asserting the set makes adding one a line somebody has to change
 * here as well, which is precisely the friction a mutating endpoint should meet.
 *
 * A route-group placement mistake is the quietest failure this feature can have — the page works, the
 * suites pass, and an address is simply reachable by people it was never meant for.
 */
final class AgentPracticeRouteTableTest extends TestCase
{
    /** Every name the agent practice group is allowed to mount, and nothing else. */
    private const ALLOWED = [
        'agent.practice-orders',
        'agent.practice-orders.demo-compare',
        'agent.practice-orders.demo-orders',
        'agent.practice-orders.demo-receipt',
        'agent.practice-orders.demo-url',
        'agent.practice-orders.job.ai-audio.file',
        'agent.practice-orders.job.original.file',
        'agent.practice-orders.job.review.fragment',
        'agent.practice-orders.job.status',
        'agent.practice-orders.store',
        'agent.practice-orders.store.arriving',
        'agent.practice-orders.store.group.recordings',
        'agent.practice-orders.store.group.transcripts',
    ];

    /**
     * Names that must never appear under the agent prefix.
     *
     * Each has an administrator counterpart that writes, spends money, or opens the full correction
     * surface. An agent address for any of them would be a capability nobody granted.
     */
    private const FORBIDDEN = [
        'agent.practice-orders.topic.assign',
        'agent.practice-orders.topic.bulk',
        'agent.practice-orders.store.group.replace',
        'agent.practice-orders.store.group.tts-options',
        'agent.practice-orders.sync-demo-orders',
        'agent.practice-orders.job.transcribe',
        'agent.practice-orders.job.download',
        'agent.practice-orders.job.ai-audio.generate',
        'agent.practice-orders.job.review',
        'agent.practice-orders.job.review.history',
        'agent.practice-orders.job.review.confirm',
        'agent.practice-orders.job.review.text',
        'agent.practice-orders.job.conversation',
        'agent.practice-orders.job.original',
        'agent.topics',
    ];

    public function testTheAgentPracticeGroupMountsExactlyTheApprovedAddresses(): void
    {
        $found = self::agentPracticeRouteNames();
        sort($found);

        $expected = self::ALLOWED;
        sort($expected);

        self::assertSame($expected, $found);
    }

    public function testNoWritingOrEditingAddressIsMountedForAgents(): void
    {
        $source = self::routeSource();
        $offenders = [];

        foreach (self::FORBIDDEN as $name) {
            if (str_contains($source, "'" . $name . "'")) {
                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The practice group carries its own middleware, and it is the practice one.
     *
     * Mounting these routes in the plain agent group would skip the actor, the per-request role
     * re-read and the object-level scope — and nothing on the page would look different.
     */
    public function testThePracticeRoutesSitBehindTheirOwnMiddleware(): void
    {
        $source = self::routeSource();

        self::assertSame(
            1,
            substr_count($source, 'RequirePracticeOrdersMiddleware::class'),
            'exactly one group carries the practice gate',
        );
        self::assertStringContainsString(
            "->middleware(RequirePracticeOrdersMiddleware::class)",
            $source,
        );
    }

    /** The agent gate itself is untouched: still exactly one group, still the one it always was. */
    public function testTheAgentGateIsStillMountedOnce(): void
    {
        self::assertSame(
            1,
            substr_count(self::routeSource(), '->middleware(RequireAgentMiddleware::class)'),
        );
    }

    /** @return list<string> */
    private static function agentPracticeRouteNames(): array
    {
        preg_match_all("/->name\\('(agent\\.practice-orders[^']*)'\\)/", self::routeSource(), $matches);

        /** @var list<string> $names */
        $names = $matches[1];

        return $names;
    }

    private static function routeSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/config/common/routes.php');
    }
}
