<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Tests\Support\WebTester;

/**
 * The root path is administrator-only. An unauthenticated browser following it lands on the login page.
 */
final class HomePageCest
{
    public function rootRedirectsToLogin(WebTester $I): void
    {
        $I->wantTo('be sent to the login page when not signed in.');
        $I->amOnPage('/');
        $I->seeCurrentUrlEquals('/login');
        $I->see('Sign in');
    }
}
