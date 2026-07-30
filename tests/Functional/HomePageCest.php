<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;

use function PHPUnit\Framework\assertSame;

/**
 * The application is administrator-only, so the root path is no longer a public landing page: an
 * unauthenticated request is redirected to the login screen.
 */
final class HomePageCest
{
    public function rootRedirectsUnauthenticatedVisitorsToLogin(FunctionalTester $tester): void
    {
        $response = $tester->sendRequest(new ServerRequest(uri: '/'));

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }
}
