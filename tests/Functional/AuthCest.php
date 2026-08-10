<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * Stateless authentication behaviour, driven in-process. The stateful happy path (which needs a
 * session-bound CSRF token and cookies) lives in the Web suite, which drives the real served app.
 */
final class AuthCest
{
    public function loginPageRendersWithACsrfField(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/login'));
        $html = $response->getBody()->getContents();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('Sign in', $html);
        assertStringContainsString('name="_csrf"', $html);
        assertStringContainsString('name="username"', $html);
        assertStringContainsString('name="password"', $html);
    }

    public function postingLoginWithoutACsrfTokenIsRejected(FunctionalTester $tester): void
    {
        $request = (new ServerRequest(method: 'POST', uri: '/login'))
            ->withParsedBody(['username' => 'admin', 'password' => 'whatever']);

        $response = $tester->sendRequest($request);

        // The global CSRF middleware refuses an unsafe request with no valid token before the action
        // ever runs, so credentials are never even evaluated.
        assertSame(422, $response->getStatusCode());
    }

    public function protectedRoutesRedirectToLogin(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/'));

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function adminRuleChatRequiresAuthentication(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/admin/rule-chat'));

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function agentRuleChatRequiresAuthentication(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/agent/rule-chat'));

        assertSame(302, $response->getStatusCode());
        assertSame('/agent/login', $response->getHeaderLine('Location'));
    }
}
