<?php

declare(strict_types=1);

namespace App\Tests\Unit\Practice;

use App\Agent\Domain\Order58Role;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function strpos;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Who reaches Topic Management: administrators and operation users, never agents.
 *
 * Read from the route file rather than by booting the router, like `AgentPracticeRouteTableTest`: what is
 * guarded is a placement decision. Every Topic Management route must sit in the top-level group whose gate
 * is `RequireOrderTestingAccessMiddleware` — which admits administrators and operation users and sends a
 * signed-in agent to their own area — and in no other group. A route left behind in the administrators'
 * group would still work for them while silently refusing operation users; one placed in the agent group
 * would hand curation to agents.
 */
final class TopicManagementAccessTest extends TestCase
{
    private const TOPIC_ROUTES = [
        'practice.topics',
        'practice.topics.store',
        'practice.topics.update',
        'practice.topics.status',
        'practice.topics.delete',
    ];

    public function testEveryTopicRouteIsInTheGroupAdministratorsAndOperationUsersShare(): void
    {
        $groups = $this->topLevelGroups();

        foreach (self::TOPIC_ROUTES as $name) {
            $holders = [];

            foreach ($groups as $gate => $body) {
                if (str_contains($body, "->name('" . $name . "')")) {
                    $holders[] = $gate;
                }
            }

            self::assertSame(['RequireOrderTestingAccessMiddleware'], $holders, $name);
        }
    }

    /** The gate those routes sit behind admits exactly the operation role of the two Order58 roles. */
    public function testThatGateAdmitsOperationUsersAndNotAgents(): void
    {
        self::assertTrue(Order58Role::Operation->canAccessOrderTesting());
        self::assertFalse(Order58Role::Agent->canAccessOrderTesting());
    }

    /** The operation user's sidebar offers the page, now that the server lets them use it. */
    public function testTheOperationSidebarListsTopicManagement(): void
    {
        $sidebar = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Web/Shared/Layout/Admin/_sidebar.php');

        self::assertSame(1, preg_match('/\$operatorMenu = \[([^\]]*)\];/', $sidebar, $m));
        self::assertStringContainsString("'Topic Management'", $m[1]);
        self::assertStringContainsString("'Manage Orders'", $m[1]);
        self::assertStringContainsString("'Practice Orders'", $m[1]);
    }

    /**
     * The route file's top-level groups, keyed by the first middleware each one declares.
     *
     * A top-level group starts at a `Group::create()` indented by exactly four spaces; it runs to the next
     * one. Nested groups (deeper indentation) stay inside their parent's text.
     *
     * @return array<string, string>
     */
    private function topLevelGroups(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/config/common/routes.php');
        preg_match_all('/^    Group::create\(\)/m', $source, $starts, PREG_OFFSET_CAPTURE);

        $groups = [];
        $offsets = [];

        foreach ($starts[0] as $start) {
            $offsets[] = (int) $start[1];
        }

        foreach ($offsets as $i => $offset) {
            $body = isset($offsets[$i + 1])
                ? substr($source, $offset, $offsets[$i + 1] - $offset)
                : substr($source, $offset);
            self::assertSame(1, preg_match('/->middleware\((\w+)::class\)/', $body, $m), 'group at ' . $offset);
            self::assertFalse(isset($groups[$m[1]]), 'two top-level groups share the gate ' . $m[1]);
            $groups[$m[1]] = $body;
        }

        self::assertArrayHasKey('RequireAdminMiddleware', $groups);
        self::assertArrayHasKey('RequireOrderTestingAccessMiddleware', $groups);
        self::assertArrayHasKey('RequireAgentMiddleware', $groups);
        self::assertNotFalse(strpos($source, 'RequireOrderTestingAccessMiddleware'));

        return $groups;
    }
}
