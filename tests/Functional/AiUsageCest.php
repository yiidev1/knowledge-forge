<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function in_array;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The usage dashboard's access control, driven in-process against the real container.
 *
 * Doubling as the DI smoke test: `FunctionalTester::sendRequest()` boots the whole application through
 * `HttpApplicationRunner`, so a misdeclared or duplicated service definition fails here rather than in
 * production. Unit tests never build the container and cannot catch that class of mistake.
 */
final class AiUsageCest
{
    private const PAGE = '/admin/openai-usage';
    private const SYNC = '/admin/openai-usage/sync';

    /**
     * A 302 to /login proves two things at once: the route IS registered (an unknown path would 404),
     * and it sits inside the group guarded by RequireAdminMiddleware.
     */
    public function pageRequiresAuthentication(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: self::PAGE));

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    /**
     * The sync endpoint must be no easier to reach than the page it refreshes.
     */
    public function syncRequiresAuthentication(FunctionalTester $tester): void
    {
        $request = (new ServerRequest(method: 'POST', uri: self::SYNC))->withParsedBody([]);

        $response = $tester->sendRequest($request);

        // Either the CSRF middleware rejects it (422) or the admin guard redirects it (302). Both are
        // refusals; what must never happen is the sync running for an anonymous caller.
        assertSame(true, in_array($response->getStatusCode(), [302, 422], true));
    }

    /**
     * Anonymous responses must not advertise the page. A hidden diagnostic that leaks its own URL on
     * the login screen is not hidden.
     */
    public function anonymousPagesDoNotMentionTheUsageUrl(FunctionalTester $tester): void
    {
        foreach (['/login', '/'] as $path) {
            $html = $tester->sendRequest(new ServerRequest(uri: $path))->getBody()->getContents();

            assertStringNotContainsString('openai-usage', $html);
        }
    }
}
