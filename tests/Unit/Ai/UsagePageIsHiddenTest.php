<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use Codeception\Test\Unit;

use function array_merge;
use function dirname;
use function file_get_contents;
use function glob;
use function str_contains;
use function strpos;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The page must stay reachable only by direct URL.
 *
 * "We simply did not add a link" is not a guarantee — someone extending the sidebar later has no way of
 * knowing this page is meant to stay out of it. These assertions turn that intention into something
 * that fails loudly, and they name the two files that are the ONLY sources of navigation in this
 * application: the hard-coded `$items` array in the sidebar, and the hand-written links on the
 * dashboard.
 */
final class UsagePageIsHiddenTest extends Unit
{
    private const ROUTE_NAME = 'ai.usage.index';
    private const PATH = 'admin/openai-usage';

    public function testSidebarHasNoLinkToTheUsagePage(): void
    {
        $sidebar = $this->read('src/Web/Shared/Layout/Admin/_sidebar.php');

        assertStringNotContainsString(self::ROUTE_NAME, $sidebar);
        assertStringNotContainsString(self::PATH, $sidebar);
        assertStringNotContainsString('openai-usage', $sidebar);
    }

    public function testDashboardHasNoLinkToTheUsagePage(): void
    {
        $dashboard = $this->read('src/Web/Dashboard/template.php');

        assertStringNotContainsString(self::ROUTE_NAME, $dashboard);
        assertStringNotContainsString('openai-usage', $dashboard);
    }

    /**
     * No other template may link to it either — that would make it discoverable by browsing.
     */
    public function testNoOtherTemplateLinksToTheUsagePage(): void
    {
        $root = dirname(__DIR__, 3);
        $templates = (array) glob($root . '/src/*/Web/*/template.php');
        $templates = array_merge($templates, (array) glob($root . '/src/Web/*/template.php'));

        foreach ($templates as $template) {
            $path = (string) $template;
            // The page's own template is naturally allowed to reference its own sync route.
            if (str_contains($path, '/Ai/Web/Usage/')) {
                continue;
            }

            assertStringNotContainsString(self::ROUTE_NAME, (string) file_get_contents($path), $path);
        }
    }

    /**
     * The counterpart: the route IS registered, and inside the protected group. A page nobody can reach
     * at all would also pass every assertion above.
     */
    public function testRouteIsRegisteredInsideTheProtectedGroup(): void
    {
        $routes = $this->read('config/common/routes.php');

        assertStringContainsString("Route::get('/admin/openai-usage')", $routes);
        assertStringContainsString("Route::post('/admin/openai-usage/sync')", $routes);
        assertStringContainsString(self::ROUTE_NAME, $routes);

        // Declared after the group opens, so RequireAdminMiddleware applies.
        $groupAt = strpos($routes, 'Group::create()');
        $routeAt = strpos($routes, "Route::get('/admin/openai-usage')");
        self::assertNotFalse($groupAt);
        self::assertNotFalse($routeAt);
        self::assertGreaterThan($groupAt, $routeAt);
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
    }
}
